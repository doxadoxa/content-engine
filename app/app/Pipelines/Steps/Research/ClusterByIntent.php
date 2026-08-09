<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Enums\SearchIntent;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Content\Squish;

/**
 * Group the pool by what the searcher wants (§4.1).
 *
 * Rule-based rather than a model call, and that is a cost decision with a
 * quality argument behind it: intent from surface form is roughly right for the
 * query shapes that matter here ("how to", "best", "near me"), and spending a
 * model call per keyword on a weekly pool of several hundred buys very little.
 * The cluster is the parent topic where the source gave one, which is the
 * source's own opinion about what belongs together.
 */
class ClusterByIntent extends AbstractStep
{
    /** @var array<string, SearchIntent> */
    private const array MARKERS = [
        'how to' => SearchIntent::Informational,
        'what is' => SearchIntent::Informational,
        'why' => SearchIntent::Informational,
        'guide' => SearchIntent::Informational,
        'checklist' => SearchIntent::Informational,
        'best' => SearchIntent::Commercial,
        'vs' => SearchIntent::Commercial,
        'versus' => SearchIntent::Commercial,
        'review' => SearchIntent::Commercial,
        'alternative' => SearchIntent::Commercial,
        'compare' => SearchIntent::Commercial,
        'price' => SearchIntent::Transactional,
        'cost' => SearchIntent::Transactional,
        'buy' => SearchIntent::Transactional,
        'hire' => SearchIntent::Transactional,
        'book' => SearchIntent::Transactional,
        'near me' => SearchIntent::Navigational,
    ];

    public static function key(): string
    {
        return 'cluster_by_intent';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [DropIrrelevant::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        // From the filtered pool. Reading FetchKeywords directly would cluster
        // the keywords the relevance step just threw out — the DAG would still
        // be satisfied, and the off-topic ones would come straight back.
        $pool = $context->hasOutput(DropIrrelevant::key())
            ? $context->output(DropIrrelevant::key(), KeywordPoolPayload::class)
            : $context->output(FetchKeywords::key(), KeywordPoolPayload::class);

        $intents = [];
        $clusters = [];

        foreach ($pool->keywords as $idea) {
            $intents[$idea->keyword] = self::intentOf($idea->keyword)->value;
            $clusters[$idea->keyword] = $idea->parentTopic ?? $idea->keyword;
        }

        return StepResult::success(new KeywordPoolPayload(
            keywords: $pool->keywords,
            intents: $intents,
            clusters: $clusters,
            source: $pool->source,
        ));
    }

    public static function intentOf(string $keyword): SearchIntent
    {
        // Both the haystack and the marker are padded, so a marker only
        // matches whole words: "vs" must not match "vsauce", and "why" must not
        // match "whyte". An earlier version also tried a prefix match as a
        // fallback, which silently undid exactly that.
        $haystack = ' '.Squish::text(mb_strtolower($keyword)).' ';

        foreach (self::MARKERS as $marker => $intent) {
            if (str_contains($haystack, ' '.$marker.' ')) {
                return $intent;
            }
        }

        // The honest default: a bare noun phrase is somebody wanting to know
        // about the thing.
        return SearchIntent::Informational;
    }
}
