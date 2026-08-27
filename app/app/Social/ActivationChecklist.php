<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ContentItemState;
use App\Integrations\Google\GooglePanel;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentGoal;
use App\Models\ContentItem;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * What is set up, what is next, and what is genuinely not reachable yet.
 *
 * The single highest-value thing the reference product does, and close to the
 * cheapest: an ordered list where done items are struck through, the next one
 * carries the only button on screen, and the rest are padlocked. It answers
 * "what do I do now" before anybody has to ask, which is why an empty Holo
 * account looks unfinished rather than broken.
 *
 * **Our locks are real, and that is a deliberate divergence.** The reference
 * padlocks every step after the current one, so its Socials checklist gates
 * "set your KPIs" behind "connect an account" — and a user whose Meta
 * connection silently fails, which is the bug that started this whole release,
 * is then locked out of the rest of the product with nothing telling them why.
 *
 * So a step here is locked only when its prerequisite is a **fact**, not a
 * position in a list: approving a post needs a post to exist, and nothing else
 * here needs anything. Setting a goal before connecting a channel is a perfectly
 * sensible order and the checklist does not pretend otherwise. The reward for
 * that honesty is that this deployment — no Meta app, `social.enabled` off — can
 * still complete most of the list, where the reference's shape would show it a
 * column of padlocks and no way forward.
 */
final class ActivationChecklist
{
    /**
     * The four questions the steps answer, in the order they arise.
     *
     * Grouping is presentation, so it could have lived in the component — but
     * the reason `goal`, `post` and `approve` belong together is that they are
     * the one loop an operator repeats every month, and that is a fact about
     * the product rather than about a card. A screen that grouped them by
     * guessing from the key would guess wrong the first time a step was added.
     */
    public const string GROUP_BRAND = 'Teach it your brand';

    public const string GROUP_MAKE = 'Make something';

    public const string GROUP_SEND = 'Send it somewhere';

    public const string GROUP_SEE = 'See what it did';

    /**
     * @return list<array{
     *     key: string,
     *     group: string,
     *     label: string,
     *     detail: string,
     *     done: bool,
     *     locked: bool,
     *     blocked_by: string|null,
     *     action: string|null,
     *     action_label: string|null,
     * }>
     */
    public static function for(Project $project, Carbon $month): array
    {
        $hasBrief = BrandBrief::activeFor($project) !== null;
        $analysed = $project->site_analysis !== [];
        $goal = ContentGoal::forMonth($month);
        $hasGoal = $goal !== null && $goal->isConfirmed();

        $posts = ContentItem::query()->social();
        $written = (clone $posts)->count();
        $approved = (clone $posts)->whereIn('state', [
            ContentItemState::Approved->value,
            ContentItemState::Published->value,
        ])->exists();

        $connected = Channel::query()
            ->where('is_enabled', true)
            ->whereNotNull('verified_at')
            ->exists();

        // Both halves of it. One without the other leaves a real hole — search
        // impressions with no sessions, or the reverse — so the step is not
        // done until the engine can see the whole trip.
        $google = app(GooglePanel::class)->connectionState($project);
        $measured = $google['search_console'] && $google['analytics'];

        return [
            self::step(
                'brief', self::GROUP_BRAND, 'Write the brand brief',
                'The voice every post is written from. Without it the engine guesses.',
                done: $hasBrief, action: '/brief', actionLabel: 'Open the brief',
            ),
            self::step(
                'analysis', self::GROUP_BRAND, 'Read the website',
                'Where the facts, the offer and the audience come from.',
                done: $analysed, action: '/onboarding', actionLabel: 'Analyse the site',
            ),
            self::step(
                'goal', self::GROUP_MAKE, 'Set this month’s goal',
                'One KPI and a cadence, so the month has something to be measured against.',
                done: $hasGoal, action: '/social', actionLabel: 'Set the goal',
            ),
            self::step(
                'post', self::GROUP_MAKE, 'Create your first post',
                'Pick an idea and write it — one idea at a time, not a whole month.',
                done: $written > 0, action: '/social', actionLabel: 'Open the board',
            ),
            self::step(
                'approve', self::GROUP_MAKE, 'Approve a post',
                'Nothing in this engine publishes without a person saying so.',
                done: $approved,
                // The only real lock on the list: there is nothing to approve
                // until something has been written.
                locked: $written === 0,
                blockedBy: $written === 0 ? 'Write a post first — nothing is waiting for approval yet.' : null,
                action: '/approvals', actionLabel: 'Open the queue',
            ),
            self::step(
                'channel', self::GROUP_SEND, 'Connect somewhere to publish',
                'Approved posts wait here until a verified channel can take them.',
                done: $connected, action: '/channels', actionLabel: 'Connect a channel',
            ),
            // Was a card of its own on the dashboard, which is a setup step
            // wearing a panel — and when that screen was folded into Home the
            // step would have had nowhere left to be announced. It is only
            // reachable by an owner, so a member sees it as a fact about the
            // project rather than as an instruction they can act on.
            self::step(
                'google', self::GROUP_SEE, 'Connect Search Console and Analytics',
                'Until you do, the engine is writing without seeing what any of it did.',
                done: $measured,
                action: '/projects/'.$project->getKey().'/edit',
                actionLabel: 'Connect Google',
            ),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     group: string,
     *     label: string,
     *     detail: string,
     *     done: bool,
     *     locked: bool,
     *     blocked_by: string|null,
     *     action: string|null,
     *     action_label: string|null,
     * }
     */
    private static function step(
        string $key,
        string $group,
        string $label,
        string $detail,
        bool $done,
        bool $locked = false,
        ?string $blockedBy = null,
        ?string $action = null,
        ?string $actionLabel = null,
    ): array {
        return [
            'key' => $key,
            'group' => $group,
            'label' => $label,
            'detail' => $detail,
            'done' => $done,
            // A finished step is never locked, whatever its prerequisite says.
            // Otherwise a project that approved a post and then had its drafts
            // cleared would show a padlock over something it has already done.
            'locked' => ! $done && $locked,
            'blocked_by' => $done ? null : $blockedBy,
            'action' => $done ? null : $action,
            'action_label' => $done ? null : $actionLabel,
        ];
    }
}
