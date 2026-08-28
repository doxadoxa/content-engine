<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Enums\OnboardingStatus;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\StripeEvent;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Support\Tenancy\CurrentProject;
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

        /** @var array<string, mixed> $object */
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        // The claim and the work are one transaction, and that is the whole of
        // this method's shape.
        //
        // Claiming first is what makes a re-delivery a no-op — Stripe delivers
        // at least once and says so. But claiming *outside* the work meant that
        // anything thrown while handling — a deadlock, a dropped connection —
        // left the claim committed and the work undone: Stripe's retry would
        // arrive, find the id already recorded, log "already handled" and
        // return. A paid subscription would never be projected, the project
        // would sit on an expired trial, and nothing anywhere would say so.
        //
        // Inside a transaction the rollback takes the claim with it, so a retry
        // is a fresh attempt. A concurrent duplicate still loses: it blocks on
        // the primary key until this commits, then reads zero inserted rows.
        DB::transaction(function () use ($id, $type, $object, $payload): void {
            $claimed = DB::table('stripe_events')->insertOrIgnore([
                'id' => $id,
                'type' => $type,
                'created_at' => Carbon::now(),
            ]);

            if ($claimed === 0) {
                Log::info('Stripe event already handled; ignored', ['event' => $id, 'type' => $type]);

                return;
            }

            // Resolved once, here, and never taken from the payload again. An
            // id Stripe names that we do not have is an ordinary event — a
            // test-mode object, another deployment's customer, a project
            // deleted since — and stamping it on the row unresolved would fail
            // the foreign key, throw a 500 back at Stripe, and buy an event
            // that can never succeed a week of retries.
            $project = str_starts_with($type, 'invoice.')
                ? $this->projectFromInvoice($object)
                : $this->project($object);

            // When Stripe says this happened, not when it reached us.
            //
            // Deduplication by event id stops the same event being applied
            // twice and says nothing about two *different* events arriving out
            // of order — which Stripe does not promise. A
            // `customer.subscription.deleted` followed by an older `updated`
            // carrying `active` would re-entitle a cancelled project; an older
            // period would roll a customer's month backwards and reset their
            // counters on the way past.
            $happenedAt = is_int($payload['created'] ?? null)
                ? Carbon::createFromTimestamp($payload['created'])
                : null;

            // The subscription row is locked before that watermark is read, and
            // the lock is held to commit.
            //
            // Claiming the event id serialises *the same* event against itself
            // and nothing else. Two different events for one project run in
            // parallel, both read the same `last_event_at`, and both decide
            // they are the newer one — then the older commits second and
            // overwrites the status, plan or period the newer just wrote. That
            // is exactly the reordering the watermark exists to prevent, put
            // back by the way it was being read.
            //
            // A project with no row yet needs no lock: `project_id` is unique
            // on that table, so two concurrent creations collide on the index.
            if ($project !== null) {
                ProjectSubscription::query()
                    ->where('project_id', $project->getKey())
                    ->lockForUpdate()
                    ->first();
            }

            $outcome = match (true) {
                $this->isStale($project, $type, $happenedAt) => 'stale',
                $type === 'customer.subscription.created',
                $type === 'customer.subscription.updated' => $this->sync($object, $project, $happenedAt),
                $type === 'customer.subscription.deleted' => $this->ended($project, $happenedAt),
                $type === 'invoice.payment_failed' => $this->failed($project),
                $type === 'invoice.payment_succeeded', $type === 'invoice.paid' => $this->paid($project),
                default => 'ignored',
            };

            StripeEvent::query()->whereKey($id)->update([
                'outcome' => $outcome,
                'project_id' => $project?->getKey(),
            ]);
        });
    }

    /**
     * A subscription appeared or changed.
     *
     * The one place a checkout becomes an entitlement. Stripe is authoritative
     * about status and dates; the *plan* comes from the metadata the checkout
     * was created with, because a price id is Stripe's name for a thing and
     * `config/billing.php` is ours.
     *
     * Three different things arrive down this one event type, and they must not
     * be treated alike:
     *
     * - **A new subscription, or a change of plan.** A new arrangement, so
     *   `assign()`: counters reset, and any bespoke limits belonging to the old
     *   arrangement go with it.
     * - **A renewal.** `renew()`, which moves the window and resets the
     *   counters and touches nothing else. This used to call `assign()`, which
     *   quietly cleared an Enterprise customer's overrides at their first
     *   renewal and moved every paying customer onto the newest price list —
     *   which is precisely what `plan_version` exists to prevent.
     * - **Everything else** — a card swapped, a quantity changed, Stripe
     *   touching metadata. Status and ids are updated; the period is not.
     *
     * @param  array<string, mixed>  $object
     */
    private function sync(array $object, ?Project $project, ?Carbon $happenedAt = null): string
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

        $rawStatus = (string) ($object['status'] ?? '');
        $status = StripeBillingProvider::statusFrom($rawStatus);
        $existing = $this->existing($project);

        // Read, and left null when Stripe sends no window we can recognise —
        // a metadata-only edit, or a payload shape the `items` fallback misses.
        //
        // The fallback to "now" belongs only to a subscription being *created*.
        // Applied to an existing one it is a bug with a bill attached: "now"
        // never equals the stored period start, so an update carrying no window
        // read as a renewal, moved the customer's month and wiped their
        // counters — handing them a fresh month's quota for one month's money,
        // which is the thing `stripe_events` exists to prevent.
        $periodStart = $this->at($object, 'current_period_start');
        $periodEnd = $this->at($object, 'current_period_end');

        $outcome = match (true) {
            $existing === null, $existing->plan !== $plan => $this->arrange(
                $project,
                $plan,
                $object,
                $periodStart ?? Carbon::now(),
                $periodEnd ?? ($periodStart ?? Carbon::now())->copy()->addMonth(),
                $existing === null,
            ),
            // A renewal needs a window Stripe actually named. No window is not
            // a new period; it is an update about something else.
            $periodStart !== null && (
                $existing->period_started_at === null
                || ! $existing->period_started_at->equalTo($periodStart)
            ) => $this->rolled(
                $project,
                $periodStart,
                $periodEnd ?? $periodStart->copy()->addMonth(),
            ),
            default => 'synced',
        };

        $subscription = $this->existing($project);

        if ($subscription === null) {
            return 'unmatched';
        }

        $subscription->fill([
            'stripe_id' => is_string($object['id'] ?? null) ? $object['id'] : null,
            // Stripe's own word, unmapped: the column records what we were
            // told, and our reduced vocabulary would destroy the difference
            // between `incomplete`, `unpaid` and `past_due`.
            'stripe_status' => $rawStatus,
            'stripe_price' => $this->priceId($object),
            'trial_ends_at' => $this->at($object, 'trial_end'),
            'canceled_at' => $this->at($object, 'canceled_at'),
        ])->save();

        $this->stamp($subscription, $happenedAt);

        // Status last, and through the transitions rather than as a column.
        //
        // Writing `past_due` straight into the row left `grace_ends_at` null,
        // and a later `invoice.payment_failed` would then find the status
        // already past due and do nothing — so there was no deadline, nothing
        // for the sweep's expiry query to find, and the project published
        // indefinitely on a dead card.
        $this->settle($project, $status);

        return $outcome;
    }

    /**
     * A new subscription, or a move to a different plan.
     *
     * @param  array<string, mixed>  $object
     */
    private function arrange(
        Project $project,
        string $plan,
        array $object,
        Carbon $periodStart,
        Carbon $periodEnd,
        bool $isNew,
    ): string {
        $this->subscriptions->assign(
            $project,
            $plan,
            $this->payer($object, $project),
            $periodStart,
            $periodEnd,
            // The version the checkout was stamped with, so a session opened
            // under one price list and completed after the next was published
            // buys the one it was opened under.
            version: $this->planVersion($object),
        );

        if ($isNew) {
            $this->startTheEngineIfWaiting($project);
        }

        return $isNew ? 'subscribed' : 'plan_changed';
    }

    /**
     * The moment a new project's engine actually starts.
     *
     * The wizard's last step takes a card and starts nothing: research is spend,
     * and spend before a subscription exists is the thing every gate in this
     * subsystem refuses. So the launch waits here, for the event that says a
     * card was accepted.
     *
     * Bounded twice. `Launching` is the state the wizard leaves behind, so a
     * project that is Draft (still being filled in) or Active (running for
     * months) is not touched — which matters, because this same event fires for
     * every renewal and plan change a customer ever makes. And a project whose
     * launch already has runs is left alone, so a webhook re-delivered after
     * the claim row was somehow lost cannot research a month twice.
     */
    private function startTheEngineIfWaiting(Project $project): void
    {
        if ($project->onboarding_status !== OnboardingStatus::Launching) {
            return;
        }

        $alreadyRunning = PipelineRun::acrossProjects()
            ->where('project_id', $project->getKey())
            ->exists();

        if ($alreadyRunning) {
            return;
        }

        // Under the project, because everything `begin()` starts is
        // tenant-scoped and this runs from a webhook with no current project at
        // all — the scope would otherwise fail closed on the first write.
        app(CurrentProject::class)->run(
            $project,
            fn () => app(ProjectLaunch::class)->begin($project),
        );
    }

    private function rolled(Project $project, Carbon $periodStart, Carbon $periodEnd): string
    {
        $this->subscriptions->renew($project, $periodStart, $periodEnd);

        return 'renewed';
    }

    /**
     * Put the row into the state Stripe describes, through the transitions.
     *
     * Each of these carries a rule with it — a grace deadline, a cancellation
     * timestamp — that a bare column write would skip.
     */
    private function settle(Project $project, BillingStatus $status): void
    {
        match ($status) {
            BillingStatus::PastDue => $this->subscriptions->markPastDue($project),
            BillingStatus::Canceled => $this->subscriptions->cancel($project),
            default => $this->healthy($project, $status),
        };
    }

    /**
     * Back to healthy: a card that worked, or a subscription reactivated.
     *
     * The status Stripe reported, not `Active` flatly. A `trialing`
     * subscription stored as active is a row that disagrees with Stripe for
     * ever — the panel flags it, and `billing:reconcile` "corrects" the same
     * drift every night without it ever going away.
     *
     * And `canceled_at` is left alone. `sync()` writes it from the payload a
     * few lines earlier: a subscription set to cancel at period end is still
     * active and still carries the date it will end on, so clearing it here
     * threw away the only record of that.
     */
    private function healthy(Project $project, BillingStatus $status): void
    {
        $subscription = $this->existing($project);

        if ($subscription === null) {
            return;
        }

        $subscription->fill([
            'status' => $status,
            'grace_ends_at' => null,
        ])->save();

        // And start the engine again if billing is what stopped it. A trial
        // that converts an hour after the sweep cancelled it would otherwise
        // leave a paying customer permanently silent: the subscription reads
        // healthy, the project is still paused, and `engine:tick` only
        // considers active projects.
        $this->subscriptions->resume($project);
    }

    private function ended(?Project $project, ?Carbon $happenedAt = null): string
    {
        if ($project === null) {
            return 'unmatched';
        }

        $this->subscriptions->cancel($project);

        $subscription = $this->existing($project);

        if ($subscription !== null) {
            $this->stamp($subscription, $happenedAt);
        }

        return 'canceled';
    }

    /**
     * Whether this event describes a state older than the one we already hold.
     *
     * Only the `customer.subscription.*` family, and that narrowing is
     * deliberate. Those events each carry a *complete* picture of the
     * subscription, so applying an older one overwrites a newer truth. An
     * invoice event does one narrow thing — start dunning, or clear it — and
     * both are idempotent and self-correcting, so holding them to a shared
     * watermark would only risk dropping a legitimate one whose second-
     * granularity timestamp happened to tie.
     */
    private function isStale(?Project $project, string $type, ?Carbon $happenedAt): bool
    {
        if ($project === null || $happenedAt === null || ! str_starts_with($type, 'customer.subscription.')) {
            return false;
        }

        $last = $this->existing($project)?->last_event_at;

        return $last !== null && $happenedAt->lessThan($last);
    }

    /** Remember how far along Stripe's own timeline we have got. */
    private function stamp(ProjectSubscription $subscription, ?Carbon $happenedAt): void
    {
        if ($happenedAt === null) {
            return;
        }

        $subscription->forceFill(['last_event_at' => $happenedAt])->save();
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

    /**
     * The price list this arrangement was sold under.
     *
     * Stamped into the checkout's metadata and read back here, so a session
     * opened under version 1 and completed after version 2 was published buys
     * version 1. Null — meaning today's list — when there is no metadata to
     * read, which is the right answer for a subscription created directly in
     * the Stripe dashboard.
     *
     * @param  array<string, mixed>  $object
     */
    private function planVersion(array $object): ?int
    {
        $version = $object['metadata']['plan_version'] ?? null;

        if (! is_string($version) && ! is_int($version)) {
            return null;
        }

        $version = (int) $version;

        return $version > 0 && app(PlanCatalog::class)->has($this->planKey($object) ?? '', $version)
            ? $version
            : null;
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
