<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts a subscription has to remember about itself.
 *
 * **When we last heard from the provider.** Event-id deduplication stops the
 * *same* event being applied twice; it does nothing about two *different*
 * events arriving out of order. Stripe does not promise order, so a
 * `customer.subscription.deleted` followed by an older `updated` carrying
 * `active` would re-entitle a cancelled project — and an older period would
 * roll a customer's month backwards and reset their counters on the way.
 *
 * **Whether billing is what stopped it.** `billing:sweep` pauses a project when
 * a trial or a grace runs out. When the money starts again the project has to
 * start again — and without knowing *why* it was paused, resuming would also
 * override an operator who paused it deliberately. This is the difference
 * between the two, recorded at the moment it is known rather than guessed at
 * later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_subscriptions', function (Blueprint $table): void {
            // The provider's own timestamp for the last event we applied, not
            // ours. Comparing our clock to theirs across a queue and a retry
            // is how a stale event gets in.
            $table->timestamp('last_event_at')->nullable()->after('stripe_price');

            $table->boolean('paused_by_billing')->default(false)->after('last_event_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['last_event_at', 'paused_by_billing']);
        });
    }
};
