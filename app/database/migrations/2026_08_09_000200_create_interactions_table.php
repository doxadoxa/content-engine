<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The duty queue (§3) — deliberately not a log.
 *
 * A log would be a table of things that happened. This is a table of things
 * that are owed: every row is either waiting for an operator or explicitly
 * finished, and the state column is what separates the two. §4.2 puts the
 * target latency in minutes because the first hour is what the algorithm
 * weighs, so the shape that matters is "what is still open", not "what came in".
 *
 * The reply text and the draft live here rather than on a content unit. An
 * answer in a thread is not a published unit: it has no slug, no locale group,
 * no schema.org type and no life after being sent. Modelling it as a unit would
 * put a hundred rows a week into the content pipeline that none of its states
 * describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // Disconnecting a channel takes its conversations with it. They are
            // unanswerable without the credentials that reached them, and an
            // orphan queue nobody can act on is worse than an empty one.
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();

            // The signal this conversation also became, when the listener
            // decided it was a reason to write something as well as something
            // to answer. Nulled rather than cascaded: reaping an expired signal
            // must not delete a conversation still owed a reply.
            $table->foreignUlid('signal_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('external_id');

            // The thread, in the platform's own terms. Both are kept because
            // they answer different questions: the parent is what we reply to,
            // the root is what groups a conversation for the operator screen —
            // and deriving one from the other means walking the thread through
            // the API for every row.
            $table->string('parent_external_id')->nullable();
            $table->string('root_external_id')->nullable();

            $table->string('author');
            $table->string('author_handle')->nullable();
            $table->text('text');
            // Text for the same reason `signals.title` is: these come from a
            // platform whose own limits are longer than varchar(255).
            $table->text('permalink')->nullable();

            $table->timestampTz('received_at');

            $table->string('state')->default('new');

            $table->text('draft_reply')->nullable();
            $table->timestampTz('draft_generated_at')->nullable();

            $table->timestampTz('answered_at')->nullable();
            $table->string('reply_external_id')->nullable();

            // Why it was left alone. §7 makes the explanation next to the
            // silence mandatory: a machine that says nothing is indistinguishable
            // from a broken one.
            $table->string('ignored_reason')->nullable();

            $table->jsonb('raw')->default('{}');

            $table->timestamps();

            // A webhook and a poll of the same thread both deliver the same
            // reply, and §4.1 runs both. Without this the operator sees the
            // conversation twice and answers it twice.
            $table->unique(['channel_id', 'external_id']);

            // The only query the duty screen makes: this project's open
            // conversations, oldest first, because latency is the metric.
            $table->index(['project_id', 'state', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
