<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the operator's day needs to record (§7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // §7: "реджект с комментарием — вход для будущей петли качества,
            // поэтому комментарий структурирован, а не свободный текст в логе".
            // A reason code plus a note: the code is what phase 9 can count,
            // the note is what a writer can act on.
            $table->json('review')->default('{}');
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::table('channels', function (Blueprint $table): void {
            // Per channel, not per project: a project may auto-publish to its
            // own blog and still want a human in front of LinkedIn.
            $table->boolean('autopublish')->default(false);

            // The last ping, so the connection wizard can say "connected" on
            // evidence rather than on the operator having pressed a button.
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn(['autopublish', 'verified_at']);
        });

        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn(['review', 'reviewed_at']);
        });
    }
};
