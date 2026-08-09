<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Support\Content\Squish;

/**
 * How much of a parent article survives into a post derived from it (§8.1).
 *
 * §8.1 asks for "единый голос: производная не может противоречить родителю в
 * фактах — проверка на пересечение сущностей", and the social spec's §4.3 then
 * names the same rule as one of `guard_policy`'s deterministic checks: "для
 * производной — существующее правило пересечения ≥34% с родителем". The word
 * that matters there is **существующее** — the rule already exists, and the
 * spec is asking the new guard to obey it rather than to invent a second one.
 *
 * Which is why this is a class and not a private method on
 * {@see SaveDerivatives}. Two copies of a threshold drift the first time one of
 * them is tuned, and the failure would be silent in the worst direction: the
 * repurpose tree and the social guard disagreeing about whether the same post
 * is about the same subject as its parent.
 *
 * Deterministic and model-free, which is not incidental. §4.3 is explicit that
 * `guard_policy` runs "без вызова модели", and a rule a model adjudicates is a
 * rule that answers differently on Tuesday.
 */
final class DerivativeOverlap
{
    /**
     * At least this share of the parent's entities must survive into the post.
     *
     * A third, roughly. Low on purpose: a 500-character post cannot carry the
     * entity load of a two-thousand-word article, and demanding that it does
     * would refuse every honest derivative. What the threshold is actually
     * for is the post that is about something else entirely, which scores near
     * zero rather than near a half.
     */
    public const float MINIMUM = 0.34;

    /**
     * Whether the post keeps enough of its parent to be called derived from it.
     *
     * @param  list<string>  $entities  the parent's entities
     */
    public static function meets(array $entities, string $body): bool
    {
        return self::share($entities, $body) >= self::MINIMUM;
    }

    /**
     * The share of the parent's entities the post mentions, 0.0 to 1.0.
     *
     * An article with no entities recorded returns 1.0 rather than 0.0. There
     * is nothing to contradict, and that is a gap in the article rather than a
     * reason to refuse its posts — the alternative would make an unannotated
     * parent unable to produce any derivative at all.
     *
     * @param  list<string>  $entities
     */
    public static function share(array $entities, string $body): float
    {
        if ($entities === []) {
            return 1.0;
        }

        $haystack = Squish::text(mb_strtolower($body));

        $found = array_filter(
            $entities,
            static fn (string $entity): bool => str_contains($haystack, Squish::text(mb_strtolower($entity))),
        );

        return count($found) / count($entities);
    }

    /** The share as a whole percentage, for a message a person reads. */
    public static function percent(float $share): int
    {
        return (int) round($share * 100);
    }
}
