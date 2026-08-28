<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether this account may have a free window for this site.
 *
 * A card-free trial is a marketing budget with a known worst case only as long
 * as somebody cannot take it repeatedly. Every trial spends real money at a
 * provider — measured, about $2.83 of model and image calls — so this is not a
 * fraud system, it is the thing that keeps a hundred signups costing $283
 * instead of an open tab.
 *
 * Three checks, and the domain one is the only one that costs an abuser
 * anything. Addresses are free and infinite; a site is not, and somebody who
 * wants a second free run at the same site has to own a second site.
 */
class TrialEligibility
{
    /**
     * The refusal, or null when the trial may start.
     *
     * A sentence rather than a code, because there is one caller and it puts
     * this on the screen. Each one says what to do next: nobody is told "no"
     * without being told what would make it a yes.
     */
    public function refusalFor(User $user, Project $project): ?string
    {
        if ($user->email_verified_at === null) {
            return 'Confirm your email address before starting the engine. We have sent you a link.';
        }

        if ($this->alreadyTrialing($user, $project)) {
            return 'You already have a project on a free trial. Finish or subscribe to that one first.';
        }

        if ($this->siteHasHadATrial($project)) {
            return 'This website has already had a free trial. Choose a plan to start it again.';
        }

        return null;
    }

    /**
     * A site's name, normalised enough to compare.
     *
     * Lower-cased, trailing dot removed, and `www.` stripped — three spellings
     * of the same site that a naive comparison would treat as three sites, and
     * therefore three trials. Deliberately *not* reduced to a registrable
     * domain: `shop.example.com` and `blog.example.com` are genuinely two sites
     * this engine would write for differently, and a public-suffix list is a
     * dependency and a maintenance burden for a rule this is already the
     * cheap half of.
     */
    public static function hostOf(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Whether a checkout for this project should carry free days at all.
     *
     * Asked at the checkout as well as at onboarding, because the checkout is
     * the other door to a trial and it used to hand one out unconditionally: a
     * customer who cancels and resubscribes came back through it, `changePlan()`
     * declined an invalid subscription, and the fallback granted the full free
     * window again. Repeatable, on the same site, for as long as somebody cared
     * to keep doing it — which is the spend bound the domain check exists to
     * hold, walked around by a different route.
     *
     * The project's own history counts as well as the site's: a project that
     * has had a trial has had its trial, whatever the domain says.
     */
    public function mayHaveATrial(Project $project): bool
    {
        $used = ProjectSubscription::query()
            ->where('project_id', $project->getKey())
            ->whereNotNull('trial_ends_at')
            ->exists();

        return ! $used && ! $this->siteHasHadATrial($project);
    }

    /**
     * One free window at a time per account, rather than one ever.
     *
     * "Ever" would be the wrong rule: somebody who trialled a site last year,
     * subscribed, cancelled and came back with a different business is a
     * customer, not an abuser. What must not happen is four trials running at
     * once, which is what makes the *concurrent* version the useful one.
     */
    private function alreadyTrialing(User $user, Project $project): bool
    {
        return ProjectSubscription::query()
            ->where('status', BillingStatus::Trialing)
            ->where('project_id', '!=', $project->getKey())
            ->whereIn('project_id', $user->projects()->select('projects.id'))
            ->exists();
    }

    /**
     * Has anybody already had a free run at this site?
     *
     * Across accounts, deliberately. Email addresses are free and unlimited, so
     * a per-account rule alone is a rule about how much typing somebody is
     * willing to do; a domain had to be bought.
     *
     * **Found by `trial_ends_at`, not by `plan = 'trial'`.** A public trial is
     * a *paid plan* at Stripe with free days on the front of it — the checkout
     * sends `medium`, and the subscription arrives as `plan = medium, status =
     * trialing`. Nothing in the normal flow ever writes `plan = 'trial'`; only
     * `billing:assign <project> trial` does, which is a comp. So the first
     * version of this predicate matched almost nothing, and the check that was
     * supposed to bound the whole cost of free trials quietly stopped
     * bounding anything.
     *
     * The end date survives conversion — `renew()` does not clear it — so this
     * keeps recognising a site that trialled and then paid, which is right: the
     * question is whether the site has had its free run, not whether it is
     * having one now.
     *
     * A trial rather than any subscription, still: a site paid for before may
     * be paid for again, and a customer who cancelled should not be locked out
     * of coming back.
     */
    private function siteHasHadATrial(Project $project): bool
    {
        $host = self::hostOf((string) $project->website_url);

        if ($host === null) {
            return false;
        }

        return DB::table('project_subscriptions')
            ->join('projects', 'projects.id', '=', 'project_subscriptions.project_id')
            ->where('project_subscriptions.project_id', '!=', $project->getKey())
            ->where(fn ($query) => $query
                ->whereNotNull('project_subscriptions.trial_ends_at')
                ->orWhere('project_subscriptions.plan', 'trial'))
            ->whereNotNull('projects.website_url')
            ->pluck('projects.website_url')
            ->contains(fn (mixed $url): bool => is_string($url) && self::hostOf($url) === $host);
    }
}
