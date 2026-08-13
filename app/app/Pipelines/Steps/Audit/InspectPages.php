<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckRegistry;
use App\Audit\Checks\IndexabilityCheck;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Run every page check over every crawled page.
 *
 * No network at all: the crawl already read each page once and stored what the
 * checks need. That is what makes this the cheap branch of the fan-out, and
 * what makes a sweep re-scorable — a check added next month can be run against
 * pages fetched today.
 *
 * It writes findings and nothing else. The per-page score and issue count are
 * left to {@see ScoreAudit}, which is downstream of all three branches: this
 * step and {@see VerifyLinks} both raise findings about the same pages and run
 * at the same time, so either one computing a total would be computing it from
 * half the evidence and racing the other to write it.
 *
 * The one piece of ordering logic is the unreadable page. A URL that answered
 * 404 has no title, no canonical, no structured data and no h1, and running the
 * other checks over it would produce eight findings that are all restatements
 * of the first. So an unreadable page gets {@see IndexabilityCheck} and nothing
 * else, and the operator sees one row saying the page is gone.
 */
class InspectPages extends AuditStep
{
    public function __construct(private readonly CheckRegistry $checks) {}

    public static function key(): string
    {
        return 'inspect_pages';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [CrawlPages::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(CrawlPages::key())) {
            return StepResult::skip('Nothing was crawled, so there is nothing to inspect.');
        }

        $crawl = $context->output(CrawlPages::key(), CrawledPagesPayload::class);
        $audit = $this->auditOrFail($crawl->auditId);

        $found = 0;
        $examined = 0;

        foreach ($audit->pages()->lazyById(100) as $page) {
            $facts = $page->facts();

            foreach ($this->checks->pageChecksFor($facts->isReadable()) as $check) {
                $found += $this->record($audit, $check, $check->run($facts), (string) $page->getKey());
            }

            $examined++;
        }

        return StepResult::success(new AuditFindingsPayload(
            auditId: $crawl->auditId,
            findings: $found,
            examined: $examined,
        ));
    }
}
