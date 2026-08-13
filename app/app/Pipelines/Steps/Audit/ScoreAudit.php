<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\AuditScore;
use App\Enums\AuditCheckGroup;
use App\Enums\AuditSeverity;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * Turn what the three branches wrote into the four numbers the screen shows,
 * and close the sweep.
 *
 * Everything is computed from the rows rather than passed in, and that is the
 * whole reason this step exists as its own node instead of being tacked onto
 * the end of the inspection. The three branches run at the same time and none
 * of them can see the others' findings: a page's issue count is this step's
 * question because it is the first moment at which the answer is complete.
 *
 * `finished_at` is written last and is what the rest of the application reads
 * as "there is an answer" — {@see SiteAudit::newest()} ignores an unfinished
 * sweep entirely, so a crawl in progress never replaces last week's numbers
 * with a half-built set of its own.
 */
class ScoreAudit extends AuditStep
{
    public function __construct(private readonly AuditScore $score) {}

    public static function key(): string
    {
        return 'score_audit';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [InspectPages::key(), VerifyLinks::key(), MeasureSpeed::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $audit = $this->auditOrFail($this->auditId($context));

        $this->scorePages($audit);

        $audit->rollUpTotals();

        $scores = $this->score->forAudit($audit);

        $speed = $this->score->blendPageSpeed(
            $scores[AuditCheckGroup::Performance->value],
            $audit->pages()->whereNotNull('speed')->get(),
        );

        // The headline is recomputed once the blended speed is known, so the
        // number on the screen is the weighted mean of the numbers beside it. A
        // headline that does not reconcile with its own components is the fault
        // this section exists to stop an operator hitting.
        $audit->forceFill([
            'seo_score' => $scores[AuditCheckGroup::Seo->value],
            'geo_score' => $scores[AuditCheckGroup::Geo->value],
            'speed_score' => $speed,
            'health_score' => $this->headline($scores, $speed),
            'finished_at' => now(),
        ])->save();

        return StepResult::success(new AuditFindingsPayload(
            auditId: (string) $audit->getKey(),
            findings: $audit->issues_found,
            examined: $audit->pages_crawled,
        ));
    }

    /**
     * Score each page from every finding against it, whichever branch wrote it.
     */
    private function scorePages(SiteAudit $audit): void
    {
        /** @var array<string, list<AuditSeverity>> $bySeverity */
        $bySeverity = [];

        $audit->issues()
            ->whereNotNull('site_audit_page_id')
            ->get()
            ->each(function (SiteAuditIssue $issue) use (&$bySeverity): void {
                $bySeverity[(string) $issue->site_audit_page_id][] = $issue->severity;
            });

        $audit->pages()->lazyById(100)->each(function (SiteAuditPage $page) use ($bySeverity): void {
            $severities = $bySeverity[(string) $page->getKey()] ?? [];

            $penalty = 0;

            foreach ($severities as $severity) {
                $penalty += $severity->penalty();
            }

            $page->forceFill([
                'score' => max(0, 100 - $penalty),
                'issues_count' => count($severities),
            ])->save();
        });
    }

    /**
     * @param  array{health: int|null, seo: int|null, geo: int|null, performance: int|null}  $scores
     */
    private function headline(array $scores, ?int $speed): ?int
    {
        $groups = [
            AuditCheckGroup::Seo->value => $scores[AuditCheckGroup::Seo->value],
            AuditCheckGroup::Geo->value => $scores[AuditCheckGroup::Geo->value],
            AuditCheckGroup::Performance->value => $speed,
        ];

        $total = 0.0;
        $weight = 0.0;

        foreach (AuditCheckGroup::cases() as $group) {
            $value = $groups[$group->value];

            if ($value === null) {
                continue;
            }

            $total += $value * $group->weight();
            $weight += $group->weight();
        }

        return $weight > 0.0 ? (int) round($total / $weight) : null;
    }

    /**
     * Which audit this run is closing.
     *
     * Read from whichever branch has an output, because any of the three may
     * legitimately have skipped: a sweep of a site with no sitemap skips all
     * three, and one on an installation with no PageSpeed key skips the third
     * every time. Falling back to the run context covers the case where every
     * branch skipped and the only record of the audit is the first step's note.
     */
    private function auditId(StepContext $context): string
    {
        foreach ([InspectPages::key(), VerifyLinks::key(), MeasureSpeed::key()] as $branch) {
            if ($context->hasOutput($branch)) {
                return $context->output($branch, AuditFindingsPayload::class)->auditId;
            }
        }

        $remembered = $context->recall('audit_id');

        if (is_string($remembered) && $remembered !== '') {
            return $remembered;
        }

        throw new TerminalStepFailure('This run has no audit to score.');
    }
}
