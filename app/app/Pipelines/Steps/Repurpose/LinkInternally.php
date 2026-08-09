<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Generation\ResolvesUnit;
use App\Support\Corpus\CorpusIndex;

/**
 * Internal links by nearest neighbour (§8.4, exit criterion 3).
 *
 * On the expensive queue because it embeds, and it indexes the parent as well
 * as querying: the corpus only knows about a unit once somebody has embedded
 * it, and the article that just went live is exactly the one everything written
 * next should be able to find.
 */
class LinkInternally extends AbstractStep
{
    use ResolvesUnit;

    public function __construct(private readonly CorpusIndex $corpus) {}

    public static function key(): string
    {
        return 'link_internally';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [LoadParent::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);

        // The vector is reused rather than recomputed: indexing and querying
        // are the same unit, and an embedding is billed per token.
        $vector = $this->corpus->index($unit);

        $links = $this->corpus->relatedTo($unit, vector: $vector);

        $unit->forceFill(['internal_links' => $links])->save();

        return StepResult::success(new LinksPayload($links));
    }
}
