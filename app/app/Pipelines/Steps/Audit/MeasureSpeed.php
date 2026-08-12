<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\AuditScore;
use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Facades\Log;

/**
 * Lighthouse, on the handful of pages a quota can afford.
 *
 * A sample rather than a survey, and the sample is chosen rather than taken
 * from the top: the home page always, then the pages the site links to most
 * from within itself. A site's own internal linking is the best free signal of
 * which pages it considers important, and it is already in the crawl.
 *
 * Skips rather than fails when there is no key, which is the state of most
 * installations. The sweep then scores performance from what the crawler
 * observed on every page by itself — response time, HTML weight, images — and
 * {@see AuditScore::blendPageSpeed()} passes that number through
 * unchanged rather than mixing it with a zero. Getting that wrong would mean
 * every site without a Google API key reporting as slow, which is a fact about
 * our configuration presented as a fact about their site.
 */
class MeasureSpeed extends AuditStep
{
    public function __construct(private readonly PageSpeedGateway $pageSpeed) {}

    public static function key(): string
    {
        return 'measure_speed';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [CrawlPages::key()];
    }

    /**
     * A Lighthouse run takes tens of seconds and the vendor is rate limited to
     * roughly one request a second, so five pages is minutes rather than
     * seconds.
     */
    public function timeout(): int
    {
        return 900;
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(CrawlPages::key())) {
            return StepResult::skip('Nothing was crawled, so there is nothing to measure.');
        }

        if ($context->get('measure_speed') === false) {
            return StepResult::skip('This sweep was started with speed measurement switched off.');
        }

        if (! $this->pageSpeed->isConfigured()) {
            return StepResult::skip(
                'No PageSpeed Insights key is configured, so page speed was not measured.'
            );
        }

        $crawl = $context->output(CrawlPages::key(), CrawledPagesPayload::class);
        $audit = $this->auditOrFail($crawl->auditId);

        $pages = $this->sample($crawl->origin, (string) $audit->getKey());

        $measured = 0;

        foreach ($pages as $page) {
            $reading = $this->pageSpeed->measure($page->url);

            if ($reading === null) {
                // The vendor had nothing to say about this page. Left null
                // rather than written as a zero, which would score the page as
                // the slowest possible one.
                Log::info('PageSpeed returned no reading for a page', ['url' => $page->url]);

                continue;
            }

            $page->forceFill(['speed' => $reading->toArray()])->save();

            $measured++;
        }

        return StepResult::success(new AuditFindingsPayload(
            auditId: $crawl->auditId,
            findings: 0,
            examined: $measured,
        ));
    }

    /**
     * The pages worth spending the quota on.
     *
     * @return list<SiteAuditPage>
     */
    private function sample(string $origin, string $auditId): array
    {
        $limit = max(1, (int) config('audit.page_speed.max_pages', 5));

        /** @var list<SiteAuditPage> $readable */
        $readable = SiteAuditPage::query()
            ->where('site_audit_id', $auditId)
            ->whereBetween('status_code', [200, 299])
            ->get()
            ->all();

        if ($readable === []) {
            return [];
        }

        $home = rtrim($origin, '/').'/';

        $inbound = $this->inboundCounts($readable);

        usort($readable, static function (SiteAuditPage $a, SiteAuditPage $b) use ($home, $inbound): int {
            // The home page first, unconditionally: it is the page an assistant
            // and a first-time visitor both land on.
            $homeFirst = ($b->url === $home ? 1 : 0) <=> ($a->url === $home ? 1 : 0);

            if ($homeFirst !== 0) {
                return $homeFirst;
            }

            // Then the pages the site links to most from within itself, which
            // is the best free signal of what it considers important. The URL
            // breaks ties so the sample is stable between sweeps.
            $byInbound = ($inbound[$b->url] ?? 0) <=> ($inbound[$a->url] ?? 0);

            return $byInbound !== 0 ? $byInbound : strcmp($a->url, $b->url);
        });

        return array_slice($readable, 0, $limit);
    }

    /**
     * How many of the crawled pages link to each one.
     *
     * @param  list<SiteAuditPage>  $pages
     * @return array<string, int>
     */
    private function inboundCounts(array $pages): array
    {
        $counts = [];

        foreach ($pages as $page) {
            foreach (array_unique($page->facts()->internalLinks) as $link) {
                $counts[$link] = ($counts[$link] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
