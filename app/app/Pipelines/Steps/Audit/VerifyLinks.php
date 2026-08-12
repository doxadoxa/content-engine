<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckFinding;
use App\Audit\Checks\BrokenLinksCheck;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Follow the internal links the crawl collected and report the dead ones.
 *
 * The one thing a check cannot do for itself, which is why {@see
 * BrokenLinksCheck} is a bare `Check` with no `run()` and the fetching lives
 * here. Two properties make the difference:
 *
 * **Deduplication across the whole sweep.** A hundred pages share one
 * navigation, so the same forty links appear on every one of them. Checked
 * per page, that is four thousand requests for forty answers. Checked here,
 * each distinct URL is fetched once and the verdict is attributed back to every
 * page that links to it.
 *
 * **A budget.** Even deduplicated this is the largest source of outbound
 * requests in the product, and an unbounded version aimed at a customer's site
 * is indistinguishable from an attack. `audit.links.max_checked` caps it; what
 * is not reached is not lost, because the next sweep of a site whose earlier
 * links were fixed spends its budget further down.
 *
 * HEAD rather than GET, because the question is whether the URL resolves and
 * the body is beside the point. A server that refuses HEAD is retried with GET
 * — that is a real configuration, and reporting a live page as broken because
 * of it would be the audit's own fault presented as the customer's.
 */
class VerifyLinks extends AuditStep
{
    public function __construct(private readonly PublicHttpClient $http) {}

    public static function key(): string
    {
        return 'verify_links';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [CrawlPages::key()];
    }

    /** Bounded by `audit.links.max_checked`, but every one of them is a request. */
    public function timeout(): int
    {
        return 1_500;
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(CrawlPages::key())) {
            return StepResult::skip('Nothing was crawled, so there are no links to follow.');
        }

        $crawl = $context->output(CrawlPages::key(), CrawledPagesPayload::class);
        $audit = $this->auditOrFail($crawl->auditId);

        $budget = max(0, (int) $context->get('max_links', config('audit.links.max_checked', 300)));

        if ($budget === 0) {
            return StepResult::skip('Link verification is switched off for this sweep.');
        }

        $check = app(BrokenLinksCheck::class);

        /** @var array<string, int|null> $verdicts  url => status, null when unreachable */
        $verdicts = [];

        /** @var array<string, list<string>> $broken  page id => dead urls */
        $broken = [];

        $crawled = $this->crawledUrls($audit->getKey());
        $checked = 0;
        $skipped = 0;

        foreach ($audit->pages()->lazyById(100) as $page) {
            foreach ($this->linksOf($page) as $link) {
                if (! array_key_exists($link, $verdicts)) {
                    // A link to a page this sweep already fetched needs no
                    // second request: we know what it answered. On a site whose
                    // internal links mostly point at its own sitemap entries,
                    // this is most of them — and it is why the budget is
                    // checked *after* this lookup rather than before. Checked
                    // before, a site with more distinct links than the budget
                    // stopped verifying entirely once it ran out, including for
                    // the links whose verdicts were already free.
                    if (array_key_exists($link, $crawled)) {
                        $verdicts[$link] = $crawled[$link];
                    } elseif ($checked < $budget) {
                        $verdicts[$link] = $this->statusOf($link, $crawl->origin);
                        $checked++;
                    } else {
                        // Out of budget for links that cost a request. The rest
                        // of the sweep still gets its free verdicts, and what is
                        // skipped here is picked up by the next sweep of a site
                        // whose earlier links have been fixed.
                        $skipped++;

                        continue;
                    }
                }

                if ($this->isDead($verdicts[$link])) {
                    $broken[(string) $page->getKey()][] = $link;
                }
            }
        }

        if ($skipped > 0) {
            // §"no silent caps": a sweep that verified less than it looked at
            // should say so somewhere, or a clean result reads as "every link
            // works" when it means "every link we could afford to try".
            Log::info('A link budget was exhausted before every distinct link was verified', [
                'audit' => $audit->getKey(),
                'checked' => $checked,
                'skipped' => $skipped,
            ]);
        }

        $found = 0;

        foreach ($broken as $pageId => $links) {
            $found += $this->record($audit, $check, [$this->finding($links, $verdicts)], $pageId);
        }

        return StepResult::success(new AuditFindingsPayload(
            auditId: $crawl->auditId,
            findings: $found,
            examined: $checked,
        ));
    }

    /**
     * One finding per page, listing its dead links.
     *
     * Not one per link: a footer with a dead link has it on every page, and a
     * page with six of them is one job rather than six.
     *
     * @param  list<string>  $links
     * @param  array<string, int|null>  $verdicts
     */
    private function finding(array $links, array $verdicts): CheckFinding
    {
        $links = array_values(array_unique($links));
        $count = count($links);

        $detail = [];

        foreach (array_slice($links, 0, 20) as $link) {
            $detail[] = ['url' => $link, 'status' => $verdicts[$link]];
        }

        return CheckFinding::medium(
            $count === 1
                ? 'One link on this page no longer resolves.'
                : "{$count} links on this page no longer resolve.",
            ['links' => $detail],
        );
    }

    /**
     * What the sweep already knows about its own pages.
     *
     * @return array<string, int|null>
     */
    private function crawledUrls(string $auditId): array
    {
        /** @var array<string, int|null> $urls */
        $urls = SiteAuditPage::query()
            ->where('site_audit_id', $auditId)
            ->pluck('status_code', 'url')
            ->map(static fn (mixed $status): ?int => is_numeric($status) ? (int) $status : null)
            ->all();

        return $urls;
    }

    /** @return list<string> */
    private function linksOf(SiteAuditPage $page): array
    {
        return $page->facts()->internalLinks;
    }

    /**
     * The status a URL answers with, or null if it could not be reached.
     */
    private function statusOf(string $url, string $origin): ?int
    {
        $timeout = (int) config('audit.links.timeout', 10);

        try {
            // Redirects followed: a link to a page that moved is a working
            // link, and reporting every 301 as broken would make the check
            // useless on any site that has ever renamed a page.
            $response = $this->http->request('HEAD', $url, $this->headers(), $timeout, 3, $origin)->response;
        } catch (ConnectionException|UnsafePublicUrl $e) {
            Log::info('A link could not be verified', ['url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }

        // 405 and 501 are the two honest ways a server says "I do not do HEAD".
        // Some say 403 instead, which is indistinguishable from a real refusal,
        // so it is retried too — a false "broken" is worse than one extra GET.
        if (in_array($response->status(), [403, 405, 501], true)) {
            try {
                return $this->http->request('GET', $url, $this->headers(), $timeout, 3, $origin)
                    ->response
                    ->status();
            } catch (ConnectionException|UnsafePublicUrl) {
                return null;
            }
        }

        return $response->status();
    }

    /**
     * A null status is a link that could not be reached at all, which for an
     * internal link on the site being audited is as dead as a 404.
     */
    private function isDead(?int $status): bool
    {
        return $status === null || $status >= 400;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['User-Agent' => (string) config('audit.crawl.user_agent', 'ContentEngine/1.0 (+audit)')];
    }
}
