<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Audit;

use App\Audit\CheckRegistry;
use App\Enums\AuditSeverity;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Content\SafeMarkdown;
use Illuminate\Support\Collection;

/**
 * Order one sweep's findings into something somebody can work through.
 *
 * The value here is not the diagnosis — the checks already did that, precisely
 * and for free. It is the sequencing: which of forty findings actually matter
 * for this site, which of them are one fix in a template rather than forty in
 * forty pages, and what to do first on a Tuesday morning. That is a judgement
 * about a particular site, which is the kind of thing worth a model call and
 * the kind of thing a static remedy string cannot make.
 *
 * The prompt is built from grouped counts rather than from every row. Forty
 * findings across a hundred pages is mostly the same eight faults repeated, and
 * sending the repetition would spend tokens teaching the model that a template
 * exists — while crowding out the three findings that are genuinely about one
 * page.
 */
class WriteFixPlan extends AbstractStep
{
    /**
     * Distinct faults described in the prompt.
     *
     * The tail below this is, by construction, the low-severity long tail: the
     * list is ordered by severity and then by how many pages carry it.
     */
    private const int MAX_GROUPS = 25;

    /** Example pages named per fault. Enough to be concrete, not a manifest. */
    private const int EXAMPLES_PER_GROUP = 3;

    public function __construct(private readonly CheckRegistry $checks) {}

    public static function key(): string
    {
        return 'write_fix_plan';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $auditId = (string) $context->get('site_audit_id');

        $audit = SiteAudit::query()->whereKey($auditId)->first();

        if ($audit === null) {
            throw new TerminalStepFailure("The audit `{$auditId}` no longer exists.");
        }

        /** @var Collection<int, SiteAuditIssue> $issues */
        $issues = $audit->issues()->with('page')->get();

        if ($issues->isEmpty()) {
            $audit->forceFill([
                'fix_plan' => 'This sweep found nothing to fix.',
                'fix_plan_written_at' => now(),
            ])->save();

            return StepResult::success(new AuditFindingsPayload($auditId, 0, 0));
        }

        $answer = $context->ask(
            'utility',
            $this->prompt($context, $audit, $issues),
            implode("\n", [
                'You are a technical SEO engineer writing a work order for the person who maintains this site.',
                '',
                'Rules:',
                '- Order by what actually costs this site traffic and citations, not by how many pages carry it.',
                '- Say plainly when several findings are one fix in a shared template or layout.',
                '- Every item names what to change and where. No general advice about SEO.',
                '- Say so when a finding is not worth acting on for this site, and why.',
                '- Do not invent findings that are not in the list, and do not restate the whole list.',
                '',
                'Format: markdown. A one-paragraph summary, then a numbered list of at most eight items, '
                    .'each a bold title and two or three sentences. No headings above level 3.',
            ]),
        );

        $plan = trim($answer->text);

        if ($plan === '') {
            // A model that answered nothing is a retryable accident rather than
            // a plan of no items — and writing the empty string would leave the
            // screen showing a fix plan that says nothing, which reads as the
            // audit having no opinion.
            throw new TerminalStepFailure('The model returned an empty fix plan.');
        }

        $audit->forceFill([
            // Stored as the markdown the model wrote, and rendered through
            // {@see SafeMarkdown} where it is shown — the same division
            // `content_items` makes between `body_markdown` and `body_html`. A
            // column holding HTML from an untrusted author is a column somebody
            // eventually echoes.
            'fix_plan' => $plan,
            'fix_plan_written_at' => now(),
        ])->save();

        return StepResult::success(new AuditFindingsPayload($auditId, $issues->count(), $audit->pages_crawled));
    }

    /**
     * @param  Collection<int, SiteAuditIssue>  $issues
     */
    private function prompt(StepContext $context, SiteAudit $audit, Collection $issues): string
    {
        $project = $context->project;

        $lines = [
            "Site: {$project->website_url}",
            "Business: {$project->name}",
            sprintf(
                'Sweep: %d pages crawled, %d findings. Health %s, SEO %s, LLM optimization %s, page speed %s.',
                $audit->pages_crawled,
                $audit->issues_found,
                $this->score($audit->health_score),
                $this->score($audit->seo_score),
                $this->score($audit->geo_score),
                $this->score($audit->speed_score),
            ),
            '',
            'Findings, worst first:',
        ];

        foreach ($this->grouped($issues) as $group) {
            $lines[] = sprintf(
                '- [%s] %s — %s (%s). Examples: %s',
                mb_strtoupper($group['severity']),
                $group['check'],
                $group['summary'],
                $group['pages'] === 0
                    ? 'affects the whole site'
                    : $group['pages'].' '.($group['pages'] === 1 ? 'page' : 'pages'),
                $group['examples'] === [] ? 'site-wide' : implode(', ', $group['examples']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Distinct faults, each with how many pages carry it and a few examples.
     *
     * Grouped on the check and the summary together: one check legitimately
     * raises different faults about one page — "no alt text" and "dated format"
     * are two jobs — and grouping on the check alone would merge them into a
     * count that means nothing.
     *
     * @param  Collection<int, SiteAuditIssue>  $issues
     * @return list<array{severity: string, check: string, summary: string, pages: int, examples: list<string>}>
     */
    private function grouped(Collection $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            // The summary carries the specifics, so it is normalised down to
            // the shape of the fault: "9 images have no alt text" and "3 images
            // have no alt text" are one job on one template.
            $shape = preg_replace('/\d+/', 'N', $issue->summary) ?? $issue->summary;

            $key = $issue->check_key.'|'.$shape;

            $groups[$key] ??= [
                'severity' => $issue->severity,
                'check' => $this->checks->labelFor($issue->check_key),
                'summary' => $shape,
                'pages' => 0,
                'examples' => [],
            ];

            if ($issue->site_audit_page_id === null) {
                continue;
            }

            $groups[$key]['pages']++;

            if (count($groups[$key]['examples']) < self::EXAMPLES_PER_GROUP && $issue->page !== null) {
                $groups[$key]['examples'][] = $issue->page->facts()->path();
            }
        }

        $ranked = [];

        foreach (AuditSeverity::ranked() as $severity) {
            $atLevel = array_filter(
                $groups,
                static fn (array $group): bool => $group['severity'] === $severity,
            );

            // Within a severity, the fault on the most pages first: it is the
            // one most likely to be a single template change.
            uasort($atLevel, static fn (array $a, array $b): int => $b['pages'] <=> $a['pages']);

            foreach ($atLevel as $group) {
                $ranked[] = [...$group, 'severity' => $severity->value];
            }
        }

        return array_slice($ranked, 0, self::MAX_GROUPS);
    }

    private function score(?int $score): string
    {
        return $score === null ? 'not measured' : $score.'/100';
    }
}
