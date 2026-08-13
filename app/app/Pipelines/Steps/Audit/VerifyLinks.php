<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckFinding;
use App\Audit\Checks\BrokenLinksCheck;
use App\Models\SiteAudit;
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
 * **A budget, and a rotation.** Even deduplicated this is the largest source of
 * outbound requests in the product, and an unbounded version aimed at a
 * customer's site is indistinguishable from an attack. `audit.links.max_checked`
 * caps it — and because a cap on a stable list would mean the tail beyond it is
 * never verified at all, {@see withinBudget()} starts each sweep further along
 * than the last. That is what makes "what is not reached this week is reached
 * later" a fact rather than a hope.
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

        // Every distinct link, and which pages carry it. Built first, from rows
        // the crawl already wrote, so the budget is spent knowing the whole
        // picture rather than in the order pages happen to arrive.
        $carriers = $this->linksByPage($audit);

        $crawled = $this->crawledUrls($audit->getKey());

        /** @var array<string, int|null> $verdicts  url => status, null when unreachable */
        $verdicts = [];

        // Free verdicts first, and all of them: a link to a page this sweep
        // already fetched needs no second request. On a site whose internal
        // links mostly point at its own sitemap entries this is most of them,
        // and they must never be crowded out by the budget — which is exactly
        // what happened when the budget was checked before this lookup.
        foreach (array_keys($carriers) as $link) {
            if (array_key_exists($link, $crawled)) {
                $verdicts[$link] = $crawled[$link];
            }
        }

        $needRequests = array_values(array_diff(array_keys($carriers), array_keys($verdicts)));

        $spending = $this->withinBudget($needRequests, $budget, $audit);

        foreach ($spending as $link) {
            $verdicts[$link] = $this->statusOf($link, $crawl->origin);
        }

        $skipped = count($needRequests) - count($spending);

        if ($skipped > 0) {
            // A sweep that verified less than it looked at has to say so
            // somewhere, or a clean result reads as "every link works" when it
            // means "every link we could afford to try".
            Log::info('A link budget was exhausted before every distinct link was verified', [
                'audit' => $audit->getKey(),
                'checked' => count($spending),
                'skipped' => $skipped,
            ]);
        }

        /** @var array<string, list<string>> $broken  page id => dead urls */
        $broken = [];

        foreach ($carriers as $link => $pageIds) {
            if (! array_key_exists($link, $verdicts) || ! $this->isDead($verdicts[$link])) {
                continue;
            }

            foreach ($pageIds as $pageId) {
                $broken[$pageId][] = $link;
            }
        }

        $found = 0;

        foreach ($broken as $pageId => $links) {
            $found += $this->record($audit, $check, [$this->finding($links, $verdicts)], $pageId);
        }

        return StepResult::success(new AuditFindingsPayload(
            auditId: $crawl->auditId,
            findings: $found,
            examined: count($spending),
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
     * Which of the links that cost a request this sweep will spend on.
     *
     * The rotation, and it applies to the *links* rather than to the pages
     * carrying them — which is the correction that matters. Rotating pages does
     * not rotate coverage: a page with no links, or one whose links were all
     * free, absorbs the offset and the sweep begins on the same link it did
     * last time. The budget is spent per distinct link, so that is the list
     * whose starting point has to move.
     *
     * Without any rotation the tail beyond the budget is *never* verified.
     * Fixing a link inside the budget does not free its slot either: the link
     * is still on the page and still costs a request to confirm it now works.
     * An earlier comment here claimed the next sweep would pick up what this
     * one skipped; it would not have, and this is what makes it true.
     *
     * The offset comes from how many sweeps this project has already finished,
     * so it needs no cursor column, no write, and no care when a sweep dies
     * half way. Ordered by URL first so the rotation moves through a stable
     * list rather than a different one each week.
     *
     * @param  list<string>  $links
     * @return list<string>
     */
    private function withinBudget(array $links, int $budget, SiteAudit $audit): array
    {
        $count = count($links);

        if ($count <= $budget) {
            return $links;
        }

        sort($links);

        // Counted by id rather than by `created_at`, for the reason
        // {@see SiteAudit::newest()} orders by both: two sweeps of a small site
        // fit inside one timestamp, and a rotation that cannot tell them apart
        // does not rotate. ULIDs sort by the time they were minted.
        $sweeps = SiteAudit::query()->where('id', '<', $audit->getKey())->count();

        $offset = ($sweeps * $budget) % $count;

        // Wrapping, so a budget that runs off the end of the list continues
        // from its start rather than coming back short.
        $rotated = array_merge(array_slice($links, $offset), array_slice($links, 0, $offset));

        return array_slice($rotated, 0, $budget);
    }

    /**
     * Every distinct internal link in the sweep, and the pages that carry it.
     *
     * Deduplicated across the whole site before anything is fetched. A hundred
     * pages share one navigation, so the same forty links appear on every one
     * of them: checked per page that is four thousand requests for forty
     * answers.
     *
     * @return array<string, list<string>> link url => page ids
     */
    private function linksByPage(SiteAudit $audit): array
    {
        $carriers = [];

        foreach ($audit->pages()->orderBy('url')->lazyById(100) as $page) {
            foreach ($this->linksOf($page) as $link) {
                $carriers[$link][] = (string) $page->getKey();
            }
        }

        return $carriers;
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
