<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\StripeEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What Stripe tells us, turned into what this application already understands.
 *
 * Listens to Cashier's `WebhookReceived` rather than `WebhookHandled`, and the
 * difference matters: `WebhookHandled` only fires for event types Cashier has a
 * method for, and `invoice.payment_failed` — the event the entire dunning
 * policy hangs on — is not one of them. Listening to the earlier event means
 * one listener sees everything.
 *
 * It reads the payload rather than Cashier's own tables. Those tables are the
 * receipt; {@see ProjectSubscription} is the row the engine consults, and
 * projecting straight from the payload means this does not depend on the order
 * Cashier happens to do its own work in.
 *
 * Every transition it makes goes through {@see Subscriptions}, which is the
 * same vocabulary the console command uses. A webhook must not be a second
 * implementation of when a period starts.
 */
class StripeWebhook
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $id = is_string($payload['id'] ?? null) ? $payload['id'] : null;
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;

        if ($id === null || $type === null) {
            return;
        }

        // Claimed before anything is acted on, so a duplicate arriving while
        // the first is still in flight loses on the primary key rather than
        // racing it. One statement, not `exists` then `insert`: the window
        // between those two is the bug.
        //
        // `insertOrIgnore` rather than insert-and-catch. Postgres aborts the
        // whole transaction on a constraint violation, so catching the
        // exception leaves every subsequent statement failing with "current
        // transaction is aborted" — a duplicate webhook would take out whatever
        // transaction happened to enclose it instead of being the no-op it is
        // supposed to be.
        $claimed = DB::table('stripe_events')->insertOrIgnore([
            'id' => $id,
            'type' => $type,
            'created_at' => Carbon::now(),
        ]);

        if ($claimed === 0) {
            Log::info('Stripe event already handled; ignored', ['event' => $id, 'type' => $type]);

            return;
        }

        /** @var array<string, mixed> $object */
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        // Resolved once, here, and never taken from the payload again. An id
        // Stripe names that we do not have is an ordinary event — a test-mode
        // object, another deployment's customer, a project deleted since — and
        // stamping it on the row unresolved would fail the foreign key, throw a
        // 500 back at Stripe, and buy an event that can never succeed a week of
        // retries.
        $project = str_starts_with($type, 'invoice.')
            ? $this->projectFromInvoice($object)
            : $this->project($object);

        $outcome = match ($type) {
            'customer.subscription.created',
            'customer.subscription.updated' => $this->sync($object, $project),
            'customer.subscription.deleted' => $this->ended($project),
            'invoice.payment_failed' => $this->failed($project),
            'invoice.payment_succeeded', 'invoice.paid' => $this->paid($project),
            default => 'ignored',
        };

        StripeEvent::query()->whereKey($id)->update([
            'outcome' => $outcome,
            'project_id' => $project?->getKey(),
        ]);
    }

    /**
     * A subscription appeared or changed.
     *
     * The one place a checkout becomes an entitlement. Stripe is authoritative
     * about status and dates; the *plan* comes from the metadata the checkout
     * was created with, because a price id is Stripe's name for a thing and
     * `config/billing.php` is ours.
     *
     * @param  array<string, mixed>  $object
     */
    private function sync(array $object, ?Project $project): string
    {
        if ($project === null) {
            return 'unmatched';
        }

        $plan = $this->planKey($object);

        if ($plan === null) {
            // Refusing rather than guessing, for the reason PlanCatalog throws:
            // defaulting up gives the product away and defaulting down locks a
            // paying customer out, and both would be silent.
            Log::error('Stripe subscription names no plan we know', [
                'project' => $project->getKey(),
                'stripe_id' => $object['id'] ?? null,
            ]);

            return 'unknown_plan';
        }

        $status = StripeBillingProvider::statusFrom((string) ($object['status'] ?? ''));
        $existing = $this->existing($project);

        // Only a *new* period resets the counters. A subscription updated for
        // any other reason — a card swapped, a quantity changed, Stripe
        // touching metadata — must not hand somebody a fresh month's quota
        // partway through the one they are paying for.
        $periodStart = $this->at($object, 'current_period_start');
        $renewed = $periodStart !== null
            && ($existing === null || $existing->period_started_at === null
                || ! $existing->period_started_at->equalTo($periodStart));

        if ($renewed) {
            $this->subscriptions->assign(
                $project,
                $plan,
                $this->payer($object, $project),
                $periodStart,
                $this->at($object, 'current_period_end'),
            );
        }

        $subscription = $this->existing($project);

        if ($subscription === null) {
            return 'unmatched';
        }

        $subscription->fill([
            'status' => $status,
            'plan' => $plan,
            'stripe_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
            'stripe_status' => (string) ($object['status'] ?? ''),
            'stripe_price' => $this->priceId($object),
            'trial_ends_at' => $this->at($object, 'trial_end'),
            'canceled_at' => $this->at($object, 'canceled_at'),
        ])->save();

        return $renewed ? 'renewed' : 'synced';
    }

    private function ended(?Project $project): string
    {
        if ($project === null) {
            return 'unmatched';
        }

        $this->subscriptions->cancel($project);

        return 'canceled';
    }

    private function failed(?Project $project): string
    {
        if ($project === null) {
            return 'unmatched';
        }

        // Generation stops now; publishing runs to the end of the grace. See
        // Subscriptions::markPastDue for why a second failure does not extend
        // it — Stripe retries an invoice several times, and taking the later
        // date each time would make dunning last as long as Stripe kept trying.
        $this->subscriptions->markPastDue($project);

        return 'past_due';
    }

    private function paid(?Project $project): string
    {
        if ($project === null) {
            return 'unmatched';
        }

        $subscription = $this->existing($project);

        if ($subscription === null) {
            return 'unmatched';
        }

        // A payment succeeding clears dunning, and nothing more. The period and
        // the counters move on `customer.subscription.updated`, which carries
        // the new window — an invoice does not, and renewing from both events
        // would reset a customer's month twice for one renewal.
        if ($subscription->status->value === 'past_due') {
            $subscription->fill(['status' => BillingStatus::Active, 'grace_ends_at' => null])->save();
        }

        return 'paid';
    }

    /** @param array<string, mixed> $object */
    private function project(array $object): ?Project
    {
        $id = $this->projectIdFrom($object);

        return $id === null ? null : Project::query()->whereKey($id)->first();
    }

    /**
     * Which project a Stripe object is about.
     *
     * Metadata first, because that is what the checkout stamped and it survives
     * a subscription being edited in the Stripe dashboard. The subscription's
     * name — Cashier's "type", which is the project ULID — is the fallback for
     * anything created outside our own checkout.
     *
     * @param  array<string, mixed>  $object
     */
    private function projectIdFrom(array $object): ?string
    {
        $fromMetadata = $object['metadata']['project_id'] ?? null;

        if (is_string($fromMetadata) && $fromMetadata !== '') {
            return $fromMetadata;
        }

        $id = is_string($object['id'] ?? null) ? $object['id'] : null;

        if ($id === null) {
            return null;
        }

        /** @var string|null $name */
        $name = DB::table('subscriptions')->where('stripe_id', $id)->value('type');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * An invoice does not carry our metadata, so it is matched by the
     * subscription it is for.
     *
     * @param  array<string, mixed>  $object
     */
    private function projectFromInvoice(array $object): ?Project
    {
        $stripeId = $object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null;

        if (! is_string($stripeId) || $stripeId === '') {
            return null;
        }

        $projectId = ProjectSubscription::query()->where('stripe_id', $stripeId)->value('project_id');

        if (is_string($projectId)) {
            return Project::query()->whereKey($projectId)->first();
        }

        /** @var string|null $name */
        $name = DB::table('subscriptions')->where('stripe_id', $stripeId)->value('type');

        return is_string($name) ? Project::query()->whereKey($name)->first() : null;
    }

    /** @param array<string, mixed> $object */
    private function planKey(array $object): ?string
    {
        $plan = $object['metadata']['plan'] ?? null;

        if (is_string($plan) && app(PlanCatalog::class)->has($plan)) {
            return $plan;
        }

        // Falling back to the price is what makes a subscription created in the
        // Stripe dashboard — an Enterprise deal, a comp — land on the right
        // plan instead of nowhere.
        $price = $this->priceId($object);

        if ($price === null) {
            return null;
        }

        foreach (app(PlanCatalog::class)->all() as $candidate) {
            if ($candidate->stripePrice === $price) {
                return $candidate->key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $object */
    private function priceId(array $object): ?string
    {
        $price = $object['items']['data'][0]['price']['id'] ?? null;

        return is_string($price) ? $price : null;
    }

    /** @param array<string, mixed> $object */
    private function payer(array $object, Project $project): ?User
    {
        $customer = $object['customer'] ?? null;

        if (is_string($customer) && $customer !== '') {
            $user = User::query()->where('stripe_id', $customer)->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        // Whoever is already paying, so a sync does not blank the payer on a
        // subscription we cannot match to a customer row.
        $existing = $this->existing($project);

        return $existing?->payer;
    }

    private function existing(Project $project): ?ProjectSubscription
    {
        return ProjectSubscription::query()->where('project_id', $project->getKey())->first();
    }

    /** @param array<string, mixed> $object */
    private function at(array $object, string $key): ?Carbon
    {
        $value = $object[$key] ?? $object['items']['data'][0][$key] ?? null;

        return is_int($value) && $value > 0 ? Carbon::createFromTimestamp($value) : null;
    }
}
