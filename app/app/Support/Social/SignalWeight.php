<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\SignalKind;
use App\Pipelines\Steps\SocialListen\Deduplicate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How much a signal is worth, 0–100 (§3, §4.1).
 *
 * `signals.weight` exists so the planner can rank, and a column where every row
 * is zero ranks nothing. The scheme below is deliberately additive, small and
 * written down, because it will be argued with: §9's whole method is that a
 * number nobody can reconstruct is a number nobody acts on.
 *
 * Four components, each answering a question the spec actually asks.
 *
 * **What kind of reason is it.** §5 says the question is the best format there
 * is and §1.3 says the reverse flow — a question becoming an article — matters
 * more than the forward one. So a question outranks everything, a conversation
 * under one of our own posts is next, and news is worth less than either
 * because the reactive band is capped at one a week and killed by its own TTL.
 *
 * **Does it resolve into the project's vocabulary.** §4.3 makes entity
 * resolution a hard gate on a native post, so a signal that resolves into
 * nothing is a signal that can never become one. It is not disqualified here —
 * an unresolved question can still be an article — but it ranks below one that
 * is demonstrably about this project.
 *
 * **How fresh it is.** §1 puts the value of a Threads post in the first hour
 * and §5 kills a reactive draft that misses its window. A subject from three
 * days ago is a worse reason to say something today than the same subject from
 * this morning. An *unknown* date scores zero here rather than full marks: a
 * feed that omits its dates should not out-rank one that publishes them.
 *
 * **Where it came from.** §1's table puts half the channel's value in "чужие
 * разговоры, а не свои посты", so a signal that came out of the degraded search
 * of §11.2 — our own posts, because the scope was never approved — is penalised
 * rather than dropped. The contour still works on less; it is just worth less.
 * Corroboration is the mirror image: one subject arriving from two independent
 * sources in the same hour is the strongest evidence this intake produces, and
 * it is free, because {@see Deduplicate} has
 * to notice the collision anyway. *Two* sources — a post the search read twice,
 * once per mode of §4.1 or once per term that matched it, is one source read
 * twice and earns nothing. `Deduplicate` decides that on the platform id; the
 * arithmetic is why it has to, since +10 is the difference between a question
 * that reaches the article planner and one that does not.
 *
 * The result is clamped, not normalised. A weight is a ranking aid inside one
 * project, and rescaling it against the project's own maximum would make
 * yesterday's numbers mean something different from today's.
 */
final class SignalWeight
{
    /** The floor and ceiling of the column (§3). */
    public const int MIN = 0;

    public const int MAX = 100;

    /** Full freshness for a subject this new; nothing for one this old. */
    private const int FRESH_HOURS = 72;

    private const int FRESHNESS_POINTS = 20;

    /** Per resolved entity, and the most they can add between them. */
    private const int ENTITY_POINTS = 5;

    private const int ENTITY_CEILING = 20;

    /** §11.2: the scope is missing and we are reading our own posts. */
    private const int DEGRADED_PENALTY = 15;

    /** One subject, two intakes, same hour (§4.1). */
    private const int CORROBORATION_BONUS = 10;

    /**
     * The starting point, by what sort of reason this is.
     *
     * @return array<value-of<SignalKind>, int>
     */
    private const array BASE = [
        'question' => 45,
        'reply' => 40,
        'mention' => 35,
        'trend' => 30,
        'seasonal' => 30,
        'own_data' => 30,
        'news' => 25,
        'repurpose' => 20,
    ];

    /**
     * What one candidate is worth at intake.
     *
     * @param  list<string>  $entities  resolved into the project's vocabulary
     */
    public static function for(
        SignalKind $kind,
        array $entities,
        ?CarbonInterface $occurredAt,
        bool $degraded = false,
        ?CarbonInterface $now = null,
    ): int {
        $weight = self::BASE[$kind->value];

        $weight += min(count($entities) * self::ENTITY_POINTS, self::ENTITY_CEILING);

        $weight += self::freshness($occurredAt, $now);

        if ($degraded) {
            $weight -= self::DEGRADED_PENALTY;
        }

        return self::clamp($weight);
    }

    /**
     * The same subject turned up somewhere else this hour — in a *different*
     * post.
     *
     * Applied by the dedup pass rather than at intake, because it is a property
     * of the batch and not of the post: nothing reading one candidate on its
     * own could know it, and nothing but the dedup pass holds the platform ids
     * that tell a second post from a second reading of the first.
     */
    public static function corroborated(int $weight): int
    {
        return self::clamp($weight + self::CORROBORATION_BONUS);
    }

    public static function clamp(int $weight): int
    {
        return max(self::MIN, min(self::MAX, $weight));
    }

    /**
     * Linear decay over three days, and zero for a date we were never given.
     *
     * Linear rather than exponential on purpose. The curve is read by a human
     * deciding whether the ranking is sane, and "one point an hour, for three
     * days" is a sentence; a half-life is a graph.
     */
    private static function freshness(?CarbonInterface $occurredAt, ?CarbonInterface $now): int
    {
        if ($occurredAt === null) {
            return 0;
        }

        $now ??= Carbon::now();

        // Positive when `occurredAt` is in the past, which is Carbon 3's sign
        // convention. Floored at zero because a platform clock a little ahead
        // of ours is not a reason for a signal to score above the ceiling.
        $hours = max(0.0, $occurredAt->diffInHours($now, absolute: false));

        if ($hours >= self::FRESH_HOURS) {
            return 0;
        }

        return (int) round(self::FRESHNESS_POINTS * (1 - $hours / self::FRESH_HOURS));
    }
}
