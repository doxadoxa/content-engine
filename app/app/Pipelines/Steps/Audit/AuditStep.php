<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckFinding;
use App\Audit\Contracts\Check;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Exceptions\TerminalStepFailure;

/**
 * The two things every step of the audit shares: which queue it runs on, and
 * how a finding becomes a row.
 *
 * The queue is the point of the class. Stating it once here rather than in six
 * steps means a step added later cannot quietly land on the shared pool — which
 * is the failure this whole separation exists to prevent, and one that would be
 * invisible until an article was late.
 */
abstract class AuditStep extends AbstractStep
{
    public function queue(): string
    {
        return (string) config('pipeline.queues.audit');
    }

    /**
     * The audit row this run is writing, or a terminal failure.
     *
     * Terminal rather than retryable: the row is created by the first step, so
     * its absence means either that step never ran — in which case the DAG is
     * wrong — or that somebody deleted it mid-sweep. Neither improves by being
     * attempted again in thirty seconds.
     */
    protected function auditOrFail(string $auditId): SiteAudit
    {
        $audit = SiteAudit::query()->whereKey($auditId)->first();

        if ($audit === null) {
            throw new TerminalStepFailure("The audit `{$auditId}` this run was writing no longer exists.");
        }

        return $audit;
    }

    /**
     * Write one check's findings against a page, or against the site.
     *
     * `updateOrCreate` rather than `create`, keyed on everything that identifies
     * the finding: a step that is retried after writing half its rows must not
     * double them. Two findings from the same check about the same page are
     * distinguished by their summary, which is what carries the specifics —
     * "9 images have no alt text" and "3 images are served in a dated format"
     * come from one check about one page and are two different jobs.
     *
     * @param  list<CheckFinding>  $findings
     * @return int how many rows this wrote
     */
    protected function record(SiteAudit $audit, Check $check, array $findings, ?string $pageId = null): int
    {
        foreach ($findings as $finding) {
            SiteAuditIssue::query()->updateOrCreate(
                [
                    'site_audit_id' => $audit->getKey(),
                    'site_audit_page_id' => $pageId,
                    'check_key' => $check::key(),
                    'summary' => $finding->summary,
                ],
                [
                    'severity' => $finding->severity,
                    'detail' => $finding->detail,
                ],
            );
        }

        return count($findings);
    }
}
