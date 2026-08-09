<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsUnavailable;
use Countable;

/**
 * The result of one search: what came back, and what happened (§11.2).
 *
 * The type is the whole argument. §11.2 gives three answers that are the same
 * empty array on the wire and must not be the same value here, so this pairs
 * every list of posts with an {@see ThreadsSearchOutcome} that says which of
 * them it is. There is no constructor that lets the two disagree —
 * {@see searched()} downgrades an empty list to {@see ThreadsSearchOutcome::Nothing}
 * itself, so "outcome says results, list is empty" is not a state that exists.
 *
 * Nothing here can be mistaken for a failure, because a failure never arrives
 * as one of these: a transport fault is
 * {@see ThreadsUnavailable} out of
 * {@see ThreadsClient}, and it propagates.
 */
final readonly class ThreadsSearchAnswer implements Countable
{
    /**
     * @param  list<ThreadsPost>  $posts
     */
    private function __construct(
        public ThreadsSearchOutcome $outcome,
        public array $posts,
    ) {}

    /**
     * The platform searched everything and answered.
     *
     * An empty list becomes {@see nothing()} rather than an empty "searched",
     * so a caller reading the outcome and a caller reading the list can never
     * reach different conclusions about the same answer.
     *
     * @param  list<ThreadsPost>  $posts
     */
    public static function searched(array $posts): self
    {
        return $posts === []
            ? self::nothing()
            : new self(ThreadsSearchOutcome::Searched, $posts);
    }

    /** §11.2's silent empty array, and an honestly quiet keyword. */
    public static function nothing(): self
    {
        return new self(ThreadsSearchOutcome::Nothing, []);
    }

    /**
     * Degraded: `threads_keyword_search` is not approved (§11.2).
     *
     * Carries posts, because the contour keeps working on less — these are the
     * project's own, and the outcome is what says so.
     *
     * @param  list<ThreadsPost>  $posts
     */
    public static function ownPostsOnly(array $posts): self
    {
        return new self(ThreadsSearchOutcome::OwnPostsOnly, $posts);
    }

    /** The 2 200/24 h window of §2 is full. Nothing was asked. */
    public static function budgetSpent(): self
    {
        return new self(ThreadsSearchOutcome::BudgetSpent, []);
    }

    /** No usable credential for this project. Nothing was asked. */
    public static function notConnected(): self
    {
        return new self(ThreadsSearchOutcome::NotConnected, []);
    }

    /** The scope of §11.2 is missing: what came back is only ours. */
    public function isDegraded(): bool
    {
        return $this->outcome === ThreadsSearchOutcome::OwnPostsOnly;
    }

    /** The window is full — a listening run should stop, not retry. */
    public function isBudgetSpent(): bool
    {
        return $this->outcome === ThreadsSearchOutcome::BudgetSpent;
    }

    /**
     * A request actually went out.
     *
     * The one question the budget accounting and the metering of §8 both ask,
     * and the two outcomes that answer no are the two where nothing was sent.
     */
    public function reachedThePlatform(): bool
    {
        return $this->outcome !== ThreadsSearchOutcome::BudgetSpent
            && $this->outcome !== ThreadsSearchOutcome::NotConnected;
    }

    public function count(): int
    {
        return count($this->posts);
    }
}
