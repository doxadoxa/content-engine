<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Enums\ContentItemType;
use App\Enums\SearchIntent;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Content\Squish;

/**
 * Type each idea, and mark the ones generation must not invent (§4.2, §4.3).
 *
 * Independent of {@see SelectTopics} on purpose: which units make the month is
 * a question about clusters and the corpus, while what *kind* of thing a unit
 * is and whether it needs real numbers is a question about the unit alone.
 * They run in parallel and the calendar reads both.
 *
 * Type comes from intent, always. An earlier draft tried to detect "the
 * operator changed this on purpose" by noticing that the stored type disagreed
 * with the intent — which is indistinguishable from research having typed it
 * before the intent was corrected, and silently kept the stale type. If a
 * human override is wanted it needs a column saying so, not an inference.
 *
 * The flag is the point of the second half. §1 makes original business data —
 * prices, cases, local specifics — the other half of the scaled-content
 * mitigation, and phase 5 will not ask an operator for data nobody said was
 * needed. A transactional query with no price on file is exactly that case.
 */
class TypeAndFlagUnits extends AbstractStep
{
    /**
     * Query shapes that cannot be answered honestly out of a model's head.
     *
     * Matched as whole words, so "rate" does not fire on "accurate" and "vs"
     * does not fire on "vsauce".
     */
    private const array NEEDS_REAL_DATA = [
        'price', 'prices', 'pricing', 'cost', 'costs', 'cheap', 'quote',
        'fee', 'fees', 'rate', 'rates', 'near me', 'best', 'review',
        'reviews', 'vs', 'versus', 'compare', 'comparison',
    ];

    public static function key(): string
    {
        return 'type_and_flag';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [GatherIdeas::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $pool = $context->output(GatherIdeas::key(), IdeaPoolPayload::class);

        $types = [];
        $needData = [];
        $ambiguous = [];

        foreach (ContentItem::query()->whereKey($pool->ideaIds)->get() as $idea) {
            // Already an enum: the model casts it. Research leaves it null on
            // nothing it created, but an operator-made unit can have none.
            $intent = $idea->intent ?? SearchIntent::Informational;
            $query = (string) $idea->target_query;

            $shape = $this->typeFromQuery($query);

            if ($shape !== null) {
                $types[$idea->getKey()] = $shape->value;
            } else {
                // The query says nothing about its shape — "cleaning lisbon"
                // could be any of three. Held back and spread below, because
                // typing all of them from intent alone made every article in a
                // month a How-to.
                $types[$idea->getKey()] = $intent->suggestedType()->value;
                $ambiguous[] = $idea->getKey();
            }

            if ($this->needsOriginalData($intent, $query)) {
                $needData[] = $idea->getKey();
            }
        }

        $types = $this->spread($types, $ambiguous);

        return StepResult::success(new TypingPayload($types, $needData));
    }

    /**
     * The shape a query asks for, when it says so.
     *
     * "how to clean marble" wants steps; "best cleaning company" wants a list;
     * "microfibre vs cotton" wants a comparison. Read off the words rather than
     * off the intent, because intent has four values and this has five and the
     * mapping between them threw away the difference.
     */
    private function typeFromQuery(string $query): ?ContentItemType
    {
        $haystack = ' '.Squish::text(mb_strtolower($query)).' ';

        // A list of pairs rather than a map: an enum case cannot be an array
        // key, and the order is load-bearing — "best cleaning service" is a
        // listicle before it is a product page.
        $markers = [
            [ContentItemType::Comparison, [' vs ', ' versus ', ' compare ', ' comparison ']],
            [ContentItemType::HowTo, [' how to ', ' how do ', ' steps ', ' guide ', ' checklist ', ' tutorial ']],
            [ContentItemType::Listicle, [' best ', ' top ', ' ideas ', ' tips ', ' ways ', ' examples ']],
            [ContentItemType::Product, [' price ', ' prices ', ' cost ', ' costs ', ' pricing ', ' near me ', ' hire ', ' book ', ' service ', ' services ', ' company ', ' companies ']],
        ];

        foreach ($markers as [$type, $words]) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    return $type;
                }
            }
        }

        // A leading number is a list whatever else it says: "7 cleaning tips".
        if (preg_match('/^\s*\d+\s/', $query) === 1) {
            return ContentItemType::Listicle;
        }

        return null;
    }

    /**
     * Give the month a mix.
     *
     * Everything the query did not label is spread across the shapes that suit
     * an unlabelled topic, in turn. A month of one type reads as a template
     * being filled in — which is what a reader and a search engine both
     * conclude — and the queries that *did* say what they wanted keep the shape
     * they asked for.
     *
     * @param  array<string, string>  $types
     * @param  list<string>  $ambiguous
     * @return array<string, string>
     */
    private function spread(array $types, array $ambiguous): array
    {
        if ($ambiguous === []) {
            return $types;
        }

        $rotation = [
            ContentItemType::Explainer,
            ContentItemType::HowTo,
            ContentItemType::Listicle,
        ];

        // Started from what is already there, so a month whose labelled queries
        // are mostly how-tos does not get more of them.
        $counts = array_count_values($types);
        $offset = ($counts[ContentItemType::HowTo->value] ?? 0) > 0 ? 1 : 0;

        foreach ($ambiguous as $index => $id) {
            $types[$id] = $rotation[($index + $offset) % count($rotation)]->value;
        }

        return $types;
    }

    private function needsOriginalData(SearchIntent $intent, string $query): bool
    {
        if ($intent === SearchIntent::Transactional || $intent === SearchIntent::Commercial) {
            return true;
        }

        $haystack = ' '.Squish::text(mb_strtolower($query)).' ';

        foreach (self::NEEDS_REAL_DATA as $marker) {
            if (str_contains($haystack, ' '.$marker.' ')) {
                return true;
            }
        }

        return false;
    }
}
