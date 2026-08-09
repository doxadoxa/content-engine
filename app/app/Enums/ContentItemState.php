<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a content unit (§2).
 *
 * The allowed edges live here rather than in the model because they are the
 * definition of the state, not a policy over it: asking "can this go to
 * published" has one answer everywhere in the engine.
 */
enum ContentItemState: string
{
    /** Came out of research. Nothing has been spent on it yet. */
    case Idea = 'idea';

    /** Accepted into a plan and waiting for a worker. */
    case Queued = 'queued';

    /** A pipeline is running against it right now. */
    case Generating = 'generating';

    /** Has a body. Waiting for a human. */
    case Draft = 'draft';

    /** A human said yes. Waiting for a delivery window. */
    case Approved = 'approved';

    /** Live on at least one channel. */
    case Published = 'published';

    /** Live, and being rewritten because the feedback loop asked for it. */
    case Refreshing = 'refreshing';

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Queued => 'Queued',
            self::Generating => 'Generating',
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Published => 'Published',
            self::Refreshing => 'Refreshing',
        };
    }

    /**
     * The states this one may become.
     *
     * `Refreshing` returns to `Draft` and not to `Published`: a refresh rewrites
     * the body, and a rewritten body is an unreviewed body. Sending it straight
     * back to published would be the one path in the engine that puts text in
     * front of readers without anyone approving it — §1 makes approve-by-default
     * the mitigation for scaled-content risk, and a dead-end `Refreshing` with
     * no way out would be the alternative.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Idea => [self::Queued],
            self::Queued => [self::Generating],
            self::Generating => [self::Draft],
            self::Draft => [self::Approved],
            self::Approved => [self::Published],
            self::Published => [self::Refreshing],
            self::Refreshing => [self::Draft],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** True once the unit is visible to readers. */
    public function isLive(): bool
    {
        return $this === self::Published || $this === self::Refreshing;
    }
}
