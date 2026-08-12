<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditScore;
use App\Audit\CheckRegistry;
use App\Audit\SiteAuditStarter;
use App\Enums\AuditCheckGroup;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Pipelines\Steps\Audit\ScoreAudit;
use App\Support\Content\SafeMarkdown;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Site audit: what the project's own website looks like to a crawler.
 *
 * Every number here comes off the {@see SiteAudit} row, which
 * {@see ScoreAudit} computed through
 * {@see AuditScore} — the same reasoning as
 * {@see VisibilityController}: a headline recomputed in the controller is a
 * headline that will one day disagree with the one the pipeline stored, and
 * nobody files a bug about a number that is merely slightly different.
 *
 * The screen reads the newest *finished* sweep and says separately whether one
 * is running. Showing a half-built sweep would mean an operator who pressed
 * Recheck watched their score collapse to nothing and climb back.
 */
class SiteAuditController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly SiteAuditStarter $audits,
        private readonly CheckRegistry $checks,
    ) {}

    public function index(SafeMarkdown $markdown): Response
    {
        $project = $this->projectOrFail();

        $audit = SiteAudit::newest();

        return Inertia::render('audit/index', [
            'audit' => $audit === null ? null : [
                'id' => $audit->getKey(),
                'health_score' => $audit->health_score,
                'seo_score' => $audit->seo_score,
                'geo_score' => $audit->geo_score,
                'speed_score' => $audit->speed_score,
                'pages_crawled' => $audit->pages_crawled,
                'issues_found' => $audit->issues_found,
                'finished_at' => $audit->finished_at?->toDateTimeString(),
                'finished_on' => $audit->finished_at?->toDateString(),
                // Counted here rather than derived on the client from `pages`,
                // which {@see pages()} caps: on a sweep with more rows than the
                // cap, a client-side subtraction reports every page beyond it
                // as clean and overstates the number by exactly the pages that
                // were dropped for being uninteresting.
                'pages_with_issues' => $audit->pages()->where('issues_count', '>', 0)->count(),
                'site_checks' => $this->siteChecks($audit),
                // Rendered here rather than on the client: the plan is markdown
                // written by a model, and it goes through the same sanitiser
                // every other stretch of model prose does.
                'fix_plan' => $audit->fix_plan === null ? null : $markdown->render($audit->fix_plan),
                'fix_plan_written_on' => $audit->fix_plan_written_at?->toDateString(),
            ],
            'pages' => $audit === null ? [] : $this->pages($audit),
            'groups' => $this->groups($audit),
            'checks' => $this->checks->describe(),
            'is_running' => $this->audits->isRunning($project),
            'is_writing_fix_plan' => $audit !== null && $this->audits->isWritingFixPlan($project, $audit),
            'fix_plan_run' => $audit === null ? null : $this->fixPlanRun($project, $audit),
            'project' => [
                'name' => $project->name,
                'website_url' => $project->website_url,
            ],
        ]);
    }

    /**
     * Start a sweep by hand.
     *
     * Refused rather than queued while one is already running — see
     * {@see SiteAuditStarter} — so an impatient second press costs nothing and
     * a customer's server is never crawled twice at once.
     */
    public function recheck(): RedirectResponse
    {
        $project = $this->projectOrFail();

        if (trim((string) $project->website_url) === '') {
            return $this->back('error', 'This project has no website address, so there is nothing to audit.');
        }

        $run = $this->audits->start($project);

        return $run === null
            ? $this->back('info', 'A sweep of this site is already running.')
            : $this->back('success', 'Reading the site now. This takes a few minutes.');
    }

    /** Ask the model to order the newest sweep's findings. */
    public function fixPlan(): RedirectResponse
    {
        $project = $this->projectOrFail();

        $audit = SiteAudit::newest();

        if ($audit === null) {
            return $this->back('error', 'There is no finished audit to write a plan from yet.');
        }

        $run = $this->audits->startFixPlan($project, $audit);

        return $run === null
            ? $this->back('info', 'A fix plan for this sweep is already being written.')
            : $this->back('success', 'Writing a fix plan from the latest sweep.');
    }

    /**
     * What became of the last request for a fix plan.
     *
     * A plan is the only thing in this section an operator presses and then
     * waits for, so it is the only one where "nothing happened" needs an
     * explanation. A failed run otherwise just puts the button back with no
     * plan beside it, which reads as a button that does not work; and a run
     * that has been going for half an hour looks exactly like one that started
     * a moment ago.
     *
     * How long it has been going is measured here rather than on the client.
     * The clock belongs to the server — it is the one that knows when the run
     * started — and reading `Date.now()` while rendering is a side effect the
     * React compiler is right to refuse.
     *
     * @return array{status: string, running_for_minutes: int|null, error: string|null}|null
     */
    private function fixPlanRun(Project $project, SiteAudit $audit): ?array
    {
        $run = $this->audits->lastFixPlanRun($project, $audit);

        if ($run === null) {
            return null;
        }

        return [
            'status' => $run->status->value,
            'running_for_minutes' => $run->started_at === null
                ? null
                : (int) $run->started_at->diffInMinutes(now(), absolute: true),
            'error' => is_string($run->error['message'] ?? null) ? $run->error['message'] : null,
        ];
    }

    /**
     * The four site-level checks, in the registry's order.
     *
     * Read from the audit's stored `site_checks` rather than recomputed, so the
     * screen shows what that sweep found rather than what today's build would
     * find. A check added since is simply absent, which the client renders as
     * "not checked in this sweep" — the honest answer.
     *
     * @return list<array{key: string, label: string, ok: bool, severity: string|null, summary: string|null}>
     */
    private function siteChecks(SiteAudit $audit): array
    {
        $stored = $audit->site_checks;

        $rows = [];

        foreach ($stored as $key => $result) {
            if (! is_array($result)) {
                continue;
            }

            $rows[] = [
                'key' => (string) $key,
                'label' => $this->checks->labelFor((string) $key),
                'ok' => (bool) ($result['ok'] ?? false),
                'severity' => isset($result['severity']) ? (string) $result['severity'] : null,
                'summary' => isset($result['summary']) ? (string) $result['summary'] : null,
            ];
        }

        return $rows;
    }

    /**
     * The pages, worst first, each with its own findings.
     *
     * Eagerly loaded and capped. The table is the screen's centre and every row
     * expands, so the issues have to come with it — but a five-hundred-page
     * sweep is not a screen, it is a report, and the pages below the cap are by
     * construction the ones with nothing wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function pages(SiteAudit $audit): array
    {
        /** @var Collection<int, SiteAuditPage> $pages */
        $pages = $audit->pages()
            ->with('issues')
            ->orderByDesc('issues_count')
            ->orderBy('score')
            ->orderBy('url')
            ->limit(200)
            ->get();

        /** @var list<array<string, mixed>> $rows */
        $rows = $pages
            ->map(fn (SiteAuditPage $page): array => [
                'id' => (string) $page->getKey(),
                'url' => $page->url,
                'path' => $page->facts()->path(),
                'score' => $page->score,
                'status_code' => $page->status_code,
                'issues_count' => $page->issues_count,
                'response_ms' => $page->response_ms,
                'speed_score' => is_array($page->speed) && isset($page->speed['score'])
                    ? (int) $page->speed['score']
                    : null,
                'issues' => $this->issues($page->issues),
            ])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * @param  Collection<int, SiteAuditIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private function issues(Collection $issues): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $issues
            ->sortBy(static fn (SiteAuditIssue $issue): int => -$issue->severity->penalty())
            ->map(fn (SiteAuditIssue $issue): array => [
                'id' => (string) $issue->getKey(),
                'check' => $issue->check_key,
                'label' => $this->checks->labelFor($issue->check_key),
                'severity' => $issue->severity->value,
                'severity_label' => $issue->severity->label(),
                'summary' => $issue->summary,
                'detail' => $issue->detail,
            ])
            ->values()
            ->all();

        return $rows;
    }

    /**
     * The three sub-scores, labelled, in a fixed order.
     *
     * Built here rather than in the page so that the label an operator reads
     * ("LLM optimization") stays the enum's business — the same string appears
     * beside the checks that feed it, and the two must not drift apart.
     *
     * @return list<array{key: string, label: string, score: int|null}>
     */
    private function groups(?SiteAudit $audit): array
    {
        $scores = [
            AuditCheckGroup::Seo->value => $audit?->seo_score,
            AuditCheckGroup::Geo->value => $audit?->geo_score,
            AuditCheckGroup::Performance->value => $audit?->speed_score,
        ];

        return array_map(static fn (AuditCheckGroup $group): array => [
            'key' => $group->value,
            'label' => $group->label(),
            'score' => $scores[$group->value],
        ], AuditCheckGroup::cases());
    }

    private function projectOrFail(): Project
    {
        $project = $this->current->get();

        if ($project === null) {
            abort(403);
        }

        return $project;
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
