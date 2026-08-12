<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\Checks\IndexabilityCheck;
use App\Audit\SiteCrawler;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Fetch the pages the sitemap named, and keep what the checks will read.
 *
 * The slowest step in the pipeline by a wide margin — one request per page,
 * sequentially, against somebody else's server — and the reason the audit has a
 * queue of its own.
 *
 * Sequential on purpose. Concurrency here would mean opening a hundred sockets
 * to a customer's site to save a few minutes of a job nobody is watching, which
 * is a rude thing to do to somebody who is paying for the audit. The budget is
 * `audit.crawl.max_pages`, and what is not reached this week is reached next
 * week — the sitemap's own order puts a site's important pages near the top far
 * more often than not.
 *
 * A page that will not load is a row with a null status rather than an
 * exception. {@see IndexabilityCheck} turns that into the
 * finding, and one dead URL out of a hundred is a result rather than a failed
 * sweep.
 */
class CrawlPages extends AuditStep
{
    public function __construct(private readonly SiteCrawler $crawler) {}

    public static function key(): string
    {
        return 'crawl_pages';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ReadSiteSignals::key()];
    }

    /**
     * Long, and longer than any other step here.
     *
     * A hundred pages at up to fifteen seconds each is the worst case this can
     * legitimately take, and the pool's own worker timeout is set above this
     * — see config/horizon.php. A step's deadline is only meaningful under its
     * worker's, which `PipelineTimeoutChainTest` holds.
     */
    public function timeout(): int
    {
        return 1_500;
    }

    public function handle(StepContext $context): StepResult
    {
        $signals = $context->output(ReadSiteSignals::key(), SiteSignalsPayload::class);

        $audit = $this->auditOrFail($signals->auditId);

        $limit = max(1, (int) $context->get('max_pages', config('audit.crawl.max_pages', 100)));
        $urls = array_slice($signals->urls, 0, $limit);

        if ($urls === []) {
            // Not a failure. A site with no reachable sitemap is a finding the
            // previous step already recorded, and the sweep should end with
            // that finding on the screen rather than with a red run.
            return StepResult::skip('The sitemap named no pages, so there was nothing to crawl.');
        }

        $crawled = 0;
        $unreachable = 0;

        foreach ($urls as $url) {
            $facts = $this->crawler->fetch($url, $signals->origin);

            SiteAuditPage::query()->updateOrCreate(
                ['site_audit_id' => $audit->getKey(), 'url' => $url],
                [
                    'status_code' => $facts->statusCode,
                    'response_ms' => $facts->responseMs,
                    'html_bytes' => $facts->htmlBytes,
                    'facts' => $facts->toArray(),
                    // Reset rather than left alone, so a re-run of this step
                    // does not leave last attempt's score beside this
                    // attempt's facts.
                    'score' => null,
                    'issues_count' => 0,
                ],
            );

            $crawled++;

            if (! $facts->isReadable()) {
                $unreachable++;
            }
        }

        $context->remember('audit_crawled', $crawled);

        return StepResult::success(new CrawledPagesPayload(
            auditId: $signals->auditId,
            origin: $signals->origin,
            crawled: $crawled,
            unreachable: $unreachable,
        ));
    }
}
