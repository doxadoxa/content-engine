<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Integrations\Exceptions\ThreadsUnavailable;

/**
 * What actually happened on a call to `GET /keyword_search` (§11.2).
 *
 * This enum exists because §11.2 describes three different things that all look
 * like an empty list from the outside, and the spec is explicit that the
 * adapter must not confuse them:
 *
 * > по «чувствительным» словам API молча отдаёт пустой массив — это не ошибка
 * > транспорта, и адаптер не должен интерпретировать её как сбой
 *
 * Returning `array` from a search would have made "the platform will never
 * answer for this word", "nobody is talking about it", "we are only allowed to
 * see our own posts" and "the daily budget is gone" the same value. Each of
 * those wants a different decision from the caller — respectively: move on,
 * come back in an hour, tell the operator the scope is missing, and stop
 * searching until the window rolls.
 *
 * A transport failure is deliberately **not** a case here. {@see ThreadsClient}
 * already raises {@see ThreadsUnavailable} for it,
 * and keeping it an exception is what makes it impossible to read a degraded or
 * an empty answer as a failure: the failure is not a value at all.
 */
enum ThreadsSearchOutcome: string
{
    /** The platform searched everything it has and found something. */
    case Searched = 'searched';

    /**
     * The platform searched and answered with nothing.
     *
     * §11.2's silent empty array lands here, and so does an honestly quiet
     * keyword — they are indistinguishable on the wire and neither is a fault.
     * Per §2 an empty answer does not count against the daily budget, so
     * nothing is spent for one.
     */
    case Nothing = 'nothing';

    /**
     * Degraded (§11.2): `threads_keyword_search` is not approved, so what came
     * back is the project's own posts and nothing else.
     *
     * Still a working contour on less, not a failure. A caller that stores
     * these must know they are ours, or the "чужие разговоры" half of §1 turns
     * into the engine listening to itself.
     */
    case OwnPostsOnly = 'own_posts_only';

    /**
     * The 2 200 requests per user per 24 h of §2 are spent. Nothing was asked.
     *
     * A value rather than an exception because a full window is the budget
     * doing its job. §5: "Бюджет — потолок, а не план. Недобор допустим,
     * перебор — нет."
     */
    case BudgetSpent = 'budget_spent';

    /**
     * There is no usable Threads credential for this project.
     *
     * Separate from every other case because it is the one an operator fixes,
     * and because a listening run sweeping every project will meet it for most
     * of them — throwing would turn the normal state of an unconnected project
     * into an error in the log every hour.
     */
    case NotConnected = 'not_connected';
}
