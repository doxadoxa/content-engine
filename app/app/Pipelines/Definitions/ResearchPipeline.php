<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Research\ClusterByIntent;
use App\Pipelines\Steps\Research\DropIrrelevant;
use App\Pipelines\Steps\Research\FetchKeywords;
use App\Pipelines\Steps\Research\ProposeAngles;
use App\Pipelines\Steps\Research\StoreIdeas;

/**
 * Research, weekly (§4.1).
 *
 *   fetch_keywords ──┬─ drop_irrelevant → cluster_by_intent → store_ideas
 *   propose_angles ──┘
 *
 * The fan-in is real rather than decorative. Expansion asks the vendor "what
 * else contains this phrase" and can only return rewordings of the seed;
 * proposal asks the business "what would a customer actually be trying to do"
 * and then asks the vendor whether anyone searches for it. Neither needs the
 * other, and a pool built from only the first is one idea in nine spellings —
 * measured on this project, and the reason the second step exists.
 *
 * Idempotent across a week by construction — `store_ideas` refuses a keyword
 * the project already has a unit for, so running it twice on Monday leaves the
 * pool exactly as the first run left it (exit criterion 1).
 */
class ResearchPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'research';
    }

    public static function version(): int
    {
        // Bumped for propose_angles: a run recorded against version 1 was
        // planned from expansion alone, and the two are not comparable.
        return 2;
    }

    public static function name(): string
    {
        return 'Research (keywords → idea pool)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            FetchKeywords::class,
            ProposeAngles::class,
            DropIrrelevant::class,
            ClusterByIntent::class,
            StoreIdeas::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            // Defaults to the project's own research_seeds when absent, so the
            // weekly schedule needs no arguments.
            'seeds' => ['sometimes', 'array'],
            'seeds.*' => ['string', 'max:255'],
        ];
    }
}
