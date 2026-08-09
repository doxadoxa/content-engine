<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a content unit needs in order to be a social post (§3).
 *
 * The delta is deliberately four columns. §3 opens by saying the post stays a
 * `ContentItem`, and the cheapest way to break that promise would be to start
 * adding a parallel table the moment the shape stopped fitting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // The canonical view for the channel adapter, and the delivery
            // journal in the same column (§3, §9):
            //
            //   {"segments":[{"text":"…","asset_id":null,
            //                 "container_id":null,"published_id":null}],
            //    "link_attachment":null,"reply_control":"everyone",
            //    "angle":"question","signal_id":null,
            //    "permalink":null,"published_root_id":null}
            //
            // It is written after *every* API call rather than once at the end,
            // and that is not caution — it is the only thing standing between a
            // retry and a second root post. Threads has no client-side
            // idempotency key and publishing is two phase (container, then
            // publish), so a job that dies between the two has no way to ask
            // the platform what it already did. Recording `container_id` and
            // `published_id` per segment as they come back is what lets a retry
            // resume at the first segment without a `published_id`.
            $table->jsonb('channel_payload')->nullable();

            // Why this unit exists. §3 calls this the whole reason the signal
            // became a table: the loop learns by source, not only by format,
            // and in a month "does the news contour pay for itself" is a group
            // by rather than an argument. Nulled rather than cascaded — reaping
            // a stale signal must never delete something we published.
            $table->foreignUlid('signal_id')->nullable()
                ->constrained()->nullOnDelete();

            // Which of the six полосы of §5 this came out of.
            $table->string('social_band')->nullable();

            // §5's reactive TTL, on the unit rather than only on the signal: a
            // draft that misses its window is killed, not published late. An
            // automatic comment on the day before yesterday's news is worse
            // than silence, and the only way to make that automatic is for the
            // deadline to travel with the draft.
            $table->timestampTz('expires_at')->nullable();

            // The governor's query, and the reason it is a three-column index
            // rather than two: the ceiling of §5 is "how many units of this
            // band did this project publish this week", so the count is a range
            // scan on published_at inside a fixed project and band.
            $table->index(['project_id', 'social_band', 'published_at']);

            // Reading a unit back to its cause, for the per-source report.
            $table->index('signal_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'social_band', 'published_at']);
            $table->dropIndex(['signal_id']);
            $table->dropConstrainedForeignId('signal_id');
            $table->dropColumn(['channel_payload', 'social_band', 'expires_at']);
        });
    }
};
