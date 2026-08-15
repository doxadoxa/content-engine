<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ContentItemState;
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
 * still complete four of five steps, where the reference's shape would show it
 * five padlocks and no way forward.
 */
final class ActivationChecklist
{
    /**
     * @return list<array{
     *     key: string,
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

        return [
            self::step(
                'brief', 'Write the brand brief',
                'The voice every post is written from. Without it the engine guesses.',
                done: $hasBrief, action: '/brief', actionLabel: 'Open the brief',
            ),
            self::step(
                'analysis', 'Read the website',
                'Where the facts, the offer and the audience come from.',
                done: $analysed, action: '/onboarding', actionLabel: 'Analyse the site',
            ),
            self::step(
                'goal', 'Set this month’s goal',
                'One KPI and a cadence, so the month has something to be measured against.',
                done: $hasGoal, action: '/social', actionLabel: 'Set the goal',
            ),
            self::step(
                'post', 'Create your first post',
                'Pick an idea and write it — one idea at a time, not a whole month.',
                done: $written > 0, action: '/social', actionLabel: 'Open the board',
            ),
            self::step(
                'approve', 'Approve a post',
                'Nothing in this engine publishes without a person saying so.',
                done: $approved,
                // The only real lock on the list: there is nothing to approve
                // until something has been written.
                locked: $written === 0,
                blockedBy: $written === 0 ? 'Write a post first — nothing is waiting for approval yet.' : null,
                action: '/approvals', actionLabel: 'Open the queue',
            ),
            self::step(
                'channel', 'Connect somewhere to publish',
                'Approved posts wait here until a verified channel can take them.',
                done: $connected, action: '/channels', actionLabel: 'Connect a channel',
            ),
        ];
    }

    /**
     * @return array{
     *     key: string,
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
