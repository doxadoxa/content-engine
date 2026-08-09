<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Models\SitePage;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Repurpose\LinksPayload;
use App\Support\Corpus\CorpusIndex;
use App\Support\Corpus\SiteLibrary;

/**
 * Internal links: to this engine's own articles, and to the site's own pages.
 *
 * The second half is the one that was missing. Linking used to run only in the
 * repurpose pipeline and only against the corpus of what this engine had
 * written — which on a new project is nothing, so every article went out with
 * zero internal links while the project's sitemap sat unread in the database.
 *
 * A link to a page that already ranks is worth more than a link to an article
 * published yesterday, so the site's own pages come first.
 */
class LinkToSite extends AbstractStep
{
    use ResolvesUnit;

    /** More than this and the article is a directory. */
    private const int MAX_LINKS = 6;

    public function __construct(
        private readonly CorpusIndex $corpus,
        private readonly SiteLibrary $library,
    ) {}

    public static function key(): string
    {
        return 'link_to_site';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteDraft::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);

        // The site's own pages.
        $fromSite = $this->library->relevantTo(
            $this->library->for($context->project),
            (string) ($unit->target_query ?? $unit->title),
            locale: $unit->locale,
        );

        // Shaped like a corpus link so the two sources are one list: the anchor
        // is what the link says, and a site page's is its title. Distance is
        // zero because these are not ranked by embedding.
        $links = array_map(
            static fn (SitePage $page): array => [
                'url' => $page->url,
                'anchor' => $page->title,
                'distance' => 0.0,
            ],
            $fromSite,
        );

        // Then whatever this engine has written that is close to it. The
        // vector is reused rather than recomputed: indexing and querying are
        // the same unit, and an embedding is billed per token.
        $vector = $this->corpus->index($unit);

        foreach ($this->corpus->relatedTo($unit, vector: $vector) as $related) {
            $links[] = $related;
        }

        // Deduplicated by URL, because a published article of ours is also a
        // page in the sitemap.
        $seen = [];
        $unique = [];

        foreach ($links as $link) {
            $url = $link['url'];

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $unique[] = $link;

            if (count($unique) >= self::MAX_LINKS) {
                break;
            }
        }

        $unit->forceFill(['internal_links' => $unique])->save();

        return StepResult::success(new LinksPayload($unique));
    }
}
