<?php

declare(strict_types=1);

namespace App\Support\Corpus;

use App\Ai\Contracts\EmbeddingGateway;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Models\ContentItem;
use Illuminate\Support\Facades\DB;

/**
 * Internal linking over the corpus (§8.4, exit criterion 3).
 *
 * Nearest neighbour on embeddings, not word overlap. The difference is the
 * point of the criterion: "window cleaning" and "glass maintenance" share no
 * words and are the same subject, while "cleaning windows" and "cleaning up a
 * codebase" share two and are not.
 *
 * Only live units are linked to. A link to a draft is a 404 on the site, and a
 * link to something that was never published is worse than no link.
 */
class CorpusIndex
{
    public function __construct(private readonly EmbeddingGateway $embeddings) {}

    /**
     * Store a unit's vector, so other units can find it.
     *
     * Returns the vector, so a caller that goes straight on to look for
     * neighbours does not pay to compute the same one twice — which is exactly
     * what the linking step does, and embeddings are billed per token.
     *
     * @return list<float>
     */
    public function index(ContentItem $unit): array
    {
        $vector = $this->embeddings->embed($this->text($unit));

        // Through the query builder with an explicit cast: `vector` is not a
        // type Eloquent can bind, and the model has no cast for it.
        DB::update(
            'update content_items set embedding = ?::vector where id = ?',
            ['['.implode(',', $vector).']', $unit->getKey()],
        );

        return $vector;
    }

    /**
     * The most related live articles, nearest first.
     *
     * Articles, not units: an internal link is an anchor in a page pointing at
     * another page of the same site, and a social post is neither. It was
     * already excluded, but only by accident — the loop below drops any row
     * without a `public_url`, and a post's permalink lives in
     * `channel_payload`. That is a coincidence of where two columns are stored,
     * so the SQL says it outright. §3 made the parent optional for a social
     * unit, which is what turned `parent_id is null` from "an article" into
     * "an article or a native post" here as everywhere else.
     *
     * @param  list<float>|null  $vector  a vector already computed for this unit
     * @return list<array{url: string, anchor: string, distance: float}>
     */
    public function relatedTo(ContentItem $unit, ?int $limit = null, ?array $vector = null): array
    {
        $limit ??= (int) config('media.links.per_unit', 3);
        $ceiling = (float) config('media.links.max_distance', 0.75);

        $vector ??= $this->embeddings->embed($this->text($unit));

        /** @var list<object{id: string, title: string, public_url: string|null, slug: string, distance: float}> $rows */
        $rows = DB::select(
            'select id, title, public_url, slug, embedding <=> ?::vector as distance
             from content_items
             where project_id = ?
               and id <> ?
               and locale = ?
               and parent_id is null
               and type <> ?
               and embedding is not null
               and state in (?, ?)
             order by distance
             limit ?',
            [
                '['.implode(',', $vector).']',
                $unit->project_id,
                $unit->getKey(),
                // Same language only: an English article linking into the
                // Portuguese site is a dead end for the reader and a signal
                // nobody wants for hreflang.
                $unit->locale,
                ContentItemType::SocialPost->value,
                ContentItemState::Published->value,
                ContentItemState::Refreshing->value,
                $limit,
            ],
        );

        $links = [];

        foreach ($rows as $row) {
            // A thin corpus should link to nothing rather than to the least
            // unrelated thing it can find.
            if ((float) $row->distance > $ceiling) {
                continue;
            }

            $url = $row->public_url;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $links[] = [
                'url' => $url,
                'anchor' => $row->title,
                'distance' => round((float) $row->distance, 4),
            ];
        }

        return $links;
    }

    /**
     * What gets embedded.
     *
     * Title, target query, entities and the summary — deliberately not the
     * whole body. The body is mostly connective prose, and embedding it buries
     * what the article is *about* under how it is written.
     */
    private function text(ContentItem $unit): string
    {
        return implode("\n", array_filter([
            $unit->title,
            $unit->target_query,
            implode(' ', $unit->entities),
            $unit->summary,
        ]));
    }
}
