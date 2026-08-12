<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckRegistry;
use App\Audit\SiteSignalReader;
use App\Models\SiteAudit;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Http\UnsafePublicUrl;

/**
 * robots.txt, sitemap.xml, llms.txt and the TLS story — the four facts that
 * belong to the site rather than to any page.
 *
 * First because two later steps depend on what it learns: the crawl has no list
 * of pages without the sitemap, and every fetch after this one is bounded to
 * the origin resolved here.
 *
 * It is also where the audit row is opened, and that is deliberate rather than
 * incidental. A sweep that dies in the crawl still leaves a row saying when it
 * started and what the site-level checks found, which is the difference between
 * an operator seeing "the sitemap is missing, and then something went wrong"
 * and seeing nothing at all.
 */
class ReadSiteSignals extends AuditStep
{
    public function __construct(
        private readonly SiteSignalReader $reader,
        private readonly CheckRegistry $checks,
    ) {}

    public static function key(): string
    {
        return 'read_site_signals';
    }

    public function handle(StepContext $context): StepResult
    {
        $project = $context->project;

        if (trim((string) $project->website_url) === '') {
            // Terminal, and it should be unreachable: the wizard sets the URL
            // in its first step and the sweep is started from that URL. Said
            // out loud rather than skipped, because a project with no website
            // producing a healthy-looking empty audit would be worse than a
            // failure somebody can read.
            throw new TerminalStepFailure('This project has no website address, so there is nothing to audit.');
        }

        try {
            $signals = $this->reader->read($project);
        } catch (UnsafePublicUrl $e) {
            throw new TerminalStepFailure("This project's website address cannot be fetched: {$e->getMessage()}");
        }

        // Keyed on the run, so a retried step adopts the row it already made
        // rather than opening a second audit for one sweep.
        $audit = SiteAudit::query()->updateOrCreate(
            ['pipeline_run_id' => $context->run->getKey()],
            ['started_at' => now(), 'site_checks' => []],
        );

        $checks = [];
        $found = 0;

        foreach ($this->checks->siteChecks() as $check) {
            $findings = $check->run($signals);

            $found += $this->record($audit, $check, $findings);

            // Recorded whether or not it found anything: the screen shows all
            // four of these with an "Ok" beside the healthy ones, and an
            // absence in this array would render as "not checked".
            $checks[$check::key()] = [
                'label' => $check->label(),
                'ok' => $findings === [],
                'severity' => $findings === [] ? null : $findings[0]->severity->value,
                'summary' => $findings === [] ? null : $findings[0]->summary,
            ];
        }

        $audit->forceFill(['site_checks' => $checks])->save();

        // No dot in the key. `remember()` writes it into the run's context array
        // literally, while `recall()` is `data_get()` and splits on dots — so
        // `audit.id` went in flat and came back null, and ScoreAudit's last
        // resort was dead code that would have failed the run it was written to
        // rescue.
        $context->remember('audit_id', $audit->getKey());

        return StepResult::success(new SiteSignalsPayload(
            auditId: (string) $audit->getKey(),
            origin: $this->originOf($signals->siteUrl),
            urls: $this->pagesToCrawl($signals->sitemapUrls, $signals->siteUrl),
        ));
    }

    /**
     * The pages this sweep will look at, home page first.
     *
     * The home page is added whether or not the sitemap names it, because it is
     * the page an assistant lands on and the one PageSpeed always measures —
     * and a surprising number of sitemaps omit it.
     *
     * @param  list<string>  $sitemapUrls
     * @return list<string>
     */
    private function pagesToCrawl(array $sitemapUrls, string $siteUrl): array
    {
        $home = rtrim($this->originOf($siteUrl), '/').'/';

        return array_values(array_unique([$home, ...$sitemapUrls]));
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new TerminalStepFailure("`{$url}` is not an address this engine can audit.");
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
