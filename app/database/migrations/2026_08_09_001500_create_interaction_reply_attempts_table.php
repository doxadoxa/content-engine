<?php

declare(strict_types=1);

use App\Enums\ReplyAttemptOutcome;
use App\Social\ReplyClearance;
use App\Social\ReplyGuardVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to answer a conversation, written before the call (§9, §10).
 *
 * §9 says it plainly: Threads has no idempotency key. A reply is a container
 * and a publish, and if the publish request leaves and nothing comes back there
 * is no call that can ask whether it landed. The interaction row cannot hold
 * that fact, because the transaction that would have written it is the one
 * being rolled back — the whole point is a record that survives the failure of
 * the thing it describes. So it lives here, one row per attempt, opened before
 * the HTTP call and closed after it.
 *
 * A row that is never closed is not a bug in this table; it is the honest shape
 * of a worker that died between the two calls, and it reads as "we do not know"
 * exactly like the failure it stands in for.
 *
 * It is also the audit trail §10 asks for. `acknowledged` holds the codes the
 * operator ticked before pressing Send — on a YMYL project the fact-check
 * acknowledgement is one of them — and `findings` holds what the guard had to
 * say about the exact words that went out, including the ones a reply already
 * posted by hand could only be told about rather than refused for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_reply_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // The conversation this was an answer to. Cascaded: an attempt with
            // no conversation is a row nobody can act on.
            $table->foreignUlid('interaction_id')->constrained()->cascadeOnDelete();

            // The person who pressed the button. Nulled rather than cascaded —
            // §4.2's requirement is that a human was there, and deleting the
            // account must not rewrite the record into one where nobody was.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // ReplyRoute: whether this engine made the call or the operator did.
            $table->string('route');
            $table->string('outcome')->default(ReplyAttemptOutcome::InFlight->value);

            // What the operator was actually looking at. Not a copy of
            // `draft_reply`: the row is closed on `draft_reply` only when the
            // attempt succeeds, and a failed attempt still has to say what was
            // nearly said.
            $table->text('text');

            // The platform's id when it answered with one, or the link the
            // operator gave for a reply they posted themselves.
            $table->string('reply_external_id')->nullable();

            /** @see ReplyClearance::ACKNOWLEDGEABLE */
            $table->jsonb('acknowledged')->default('[]');

            /** @see ReplyGuardVerdict::toArray() */
            $table->jsonb('findings')->nullable();

            // Why it ended the way it did, in the words the operator was given.
            $table->text('detail')->nullable();

            $table->timestamps();

            // The duty screen's question: does this conversation have an
            // attempt nobody closed?
            $table->index(['interaction_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_reply_attempts');
    }
};
