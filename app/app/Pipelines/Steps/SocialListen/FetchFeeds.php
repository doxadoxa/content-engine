<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Integrations\Feeds\FeedItem;
use App\Integrations\Feeds\FeedReader;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Facades\Log;

/**
 * The project's RSS whitelist, read (§4.1).
 *
 * The third intake, and the only one pointed at addresses an operator typed.
 * One broken feed must not fail the step, and the guarantee is
 * {@see FeedReader}'s rather than this class's: `read()` never throws, and
 * answers a refused address, a timeout, a 500 or a body that is not XML with an
 * empty list. That contract is why this step calls it once per address instead
 * of handing it the whole list — {@see FeedReader::readAll()} would work, but
 * it flattens twenty feeds into one array and loses which of them said nothing,
 * and a feed that has quietly stopped answering is the only failure in this
 * contour an operator can actually fix.
 *
 * Nothing here fetches an item's own link. Feed contents are data; the outbound
 * policy validates the addresses on the whitelist, and an address found inside
 * somebody else's XML is not one of them.
 */
class FetchFeeds extends AbstractStep
{
    public function __construct(private readonly FeedReader $feeds) {}

    public static function key(): string
    {
        return 'fetch_feeds';
    }

    public function handle(StepContext $context): StepResult
    {
        $urls = $context->project->feedUrls();

        if ($urls === []) {
            // The normal case. §4.1 lists the whitelist as one intake of three,
            // and a project with none still has the other two.
            return StepResult::skip('This project has no feeds on its whitelist.');
        }

        $items = [];
        $silent = [];

        foreach ($urls as $url) {
            $read = $this->feeds->read($url);

            if ($read === []) {
                // Either empty or broken, and from out here they are the same
                // thing. Recorded rather than raised: a whitelist where the
                // least reliable publisher decides whether the others are read
                // is the outcome the reader's own docblock refuses.
                $silent[] = $url;

                continue;
            }

            foreach ($read as $item) {
                $items[] = $this->row($item);
            }
        }

        if ($silent !== []) {
            Log::info('Some project feeds gave nothing this hour', [
                'project' => $context->project->slug,
                'feeds' => $silent,
                'read' => count($items),
            ]);
        }

        $context->remember('social_listen.feed_items', count($items));

        return StepResult::success(new FeedHarvestPayload($items, $silent));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(FeedItem $item): array
    {
        return [
            'feed_url' => $item->feedUrl,
            'feed_title' => $item->feedTitle,
            'id' => $item->id,
            'title' => $item->title,
            'url' => $item->url,
            'summary' => $item->summary,
            // Nullable and honestly so — see {@see FeedItem}. A feed that omits
            // its dates produces signals of unknown age rather than signals
            // that look like they happened at the moment we read them, and
            // {@see \App\Support\Social\SignalWeight} scores the unknown at
            // zero freshness rather than at full marks.
            'published_at' => $item->publishedAt?->toIso8601String(),
        ];
    }
}
