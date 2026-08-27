<?php

declare(strict_types=1);

use App\Models\WebhookDelivery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Stripe event we have already acted on.
 *
 * Stripe delivers at least once and says so: a webhook can arrive twice, out of
 * order, or days late after a retry. Without this, a replayed
 * `invoice.payment_succeeded` renews a period that has already been renewed —
 * resetting a customer's counters mid-month and handing them a second month's
 * quota for one month's money.
 *
 * This repository already holds the line on exactly this for *outbound*
 * deliveries ({@see WebhookDelivery} and its `dispatch_key`).
 * Inbound gets the same treatment rather than a different one.
 *
 * The row is written **before** the event is acted on and in the same
 * transaction, so a duplicate that arrives while the first is still running
 * loses on the unique index rather than racing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table): void {
            // Stripe's own id (`evt_...`) is the primary key. Ours would be a
            // second identifier for a thing that already has one, and the whole
            // point here is that the *provider's* notion of "the same event"
            // is what decides.
            $table->string('id')->primary();

            $table->string('type');

            // Which project it turned out to be about, when we could tell.
            // Nullable because plenty of Stripe events are about a customer
            // rather than a subscription, and recording "handled, concerned
            // nobody" is the point of writing the row at all.
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();

            // What we did about it, in a word, so the panel can show a webhook
            // that arrived and changed nothing without anybody guessing whether
            // it was dropped.
            $table->string('outcome')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
