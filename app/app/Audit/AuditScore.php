<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Contracts\SiteCheck;
use App\Enums\AuditCheckGroup;
use App\Enums\AuditSeverity;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use App\Models\SiteAuditPage;
use App\Pipelines\Steps\Audit\InspectPages;
use Illuminate\Support\Collection;

/**
 * The one place a site audit turns into numbers.
 *
 * Written as a single class for the reason {@see
 * \App\Visibility\VisibilityReport} was: the score appears on the audit screen,
 * on a page row, and in the prompt the fix plan is written from, and three
 * places computing a headline three ways is the sort of disagreement nobody
 * files a bug about and everybody quietly stops trusting the product over.
 *
 * The model is deliberately simple enough to explain to an operator in one
 * sentence per line:
 *
 *   - A page starts at 100 and loses each finding's severity penalty.
 *   - A group's score is the mean of the page scores counting only that
 *     group's findings, with the site-level findings applied on top.
 *   - The headline is the weighted mean of the groups that were measured.
 *
 * "Measured" is what most of the care here is about. A group with no checks
 * that could run — every group on a sweep that crawled nothing, which is what a
 * site with an unreachable sitemap produces — scores null, and null is dropped
 * from the headline with the remaining weights renormalised. The alternative is
 * scoring it zero, which drags a healthy site's headline down by a fifth for a
 * fact about our own reach rather than about their site.
 *
 * Performance is the group where the distinction is subtlest and is worth
 * stating. It has two sources: what the crawler observed on every page it
 * touched (response time, HTML weight, images) and what Lighthouse said about
 * the handful of pages a quota allows. An installation with no PageSpeed key
 * still has the first, so the group is measured — {@see blendPageSpeed()}
 * simply returns the findings-based number unchanged. It goes null only when
 * there were no pages at all.
 */
class AuditScore
{
    /**
     * How much a site-level finding costs the group it belongs to.
     *
     * A multiplier on the ordinary penalty rather than a separate table, so the
     * severities keep their relative order. Heavier than a page finding because
     * it is true of every page at once: a `Disallow: /` is not one page's
     * problem, and averaging it in as though it were one row among a hundred
     * would leave a de-indexed site scoring in the nineties.
     */
    private const float SITE_FINDING_MULTIPLIER = 2.5;

    public function __construct(private readonly CheckRegistry $checks) {}

    /**
     * Score one page from its own findings.
     *
     * @param  list<CheckFinding>  $findings
     */
    public function forPage(array $findings): int
    {
        $penalty = 0;

        foreach ($findings as $finding) {
            $penalty += $finding->severity->penalty();
        }

        return max(0, 100 - $penalty);
    }

    /**
     * Roll a stored sweep up into the four numbers the header carries.
     *
     * Reads the rows the steps wrote rather than taking them as arguments: the
     * three writing steps run in parallel and none of them holds the whole
     * picture, so the only complete view is the one in the database.
     *
     * @return array{health: int|null, seo: int|null, geo: int|null, performance: int|null}
     */
    public function forAudit(SiteAudit $audit): array
    {
        /** @var Collection<int, SiteAuditIssue> $issues */
        $issues = $audit->issues()->get();

        // Split, because a group is averaged over the pages its checks actually
        // looked at. An unreadable page ran only the indexability check, so it
        // belongs in that check's group's denominator and in no other's.
        $readable = $audit->pages()->whereBetween('status_code', [200, 299])->count();
        $unreadable = max(0, $audit->pages()->count() - $readable);

        $groups = [];

        foreach (AuditCheckGroup::cases() as $group) {
            $groups[$group->value] = $this->forGroup($group, $issues, $readable, $unreadable);
        }

        return [
            'health' => $this->headline($groups),
            'seo' => $groups[AuditCheckGroup::Seo->value],
            'geo' => $groups[AuditCheckGroup::Geo->value],
            'performance' => $groups[AuditCheckGroup::Performance->value],
        ];
    }

    /**
     * Fold the PageSpeed readings into the performance picture.
     *
     * Kept apart from the finding-based scoring above because it is a different
     * kind of number: Lighthouse already reports 0–100 for a page, so there is
     * nothing to penalise — the readings are averaged and blended with what the
     * findings produced. Null in, null out.
     *
     * @param  Collection<int, SiteAuditPage>  $pages
     */
    public function blendPageSpeed(?int $findingScore, Collection $pages): ?int
    {
        $scores = $pages
            ->map(static fn (SiteAuditPage $page): ?int => is_array($page->speed) && isset($page->speed['score'])
                ? (int) $page->speed['score']
                : null)
            ->filter(static fn (?int $score): bool => $score !== null)
            ->values();

        if ($scores->isEmpty()) {
            return $findingScore;
        }

        $lighthouse = (int) round($scores->average() ?? 0);

        if ($findingScore === null) {
            return $lighthouse;
        }

        // Two thirds Lighthouse: it measures what a visitor experiences, where
        // the crawler's own timings are a proxy for it. The proxy still counts,
        // because it covers every page and Lighthouse covers a handful.
        return (int) round($lighthouse * 0.67 + $findingScore * 0.33);
    }

    /**
     * The worst findings first, for the fix plan's prompt and the screen.
     *
     * @param  Collection<int, SiteAuditIssue>  $issues
     * @return Collection<int, SiteAuditIssue>
     */
    public function ranked(Collection $issues): Collection
    {
        $order = [];

        foreach (AuditSeverity::ranked() as $position => $severity) {
            $order[$severity->value] = $position;
        }

        return $issues->sortBy(static fn (SiteAuditIssue $issue): int => $order[$issue->severity->value] ?? 99)
            ->values();
    }

    /**
     * One group's score, or null if nothing in it was measured.
     *
     * @param  Collection<int, SiteAuditIssue>  $issues
     */
    private function forGroup(
        AuditCheckGroup $group,
        Collection $issues,
        int $readable,
        int $unreadable,
    ): ?int {
        $pageCount = $this->pagesLookedAt($group, $readable, $unreadable);

        // Nothing in this group ran at all. Not the same as "it ran and found
        // nothing", which scores 100.
        if (! $this->wasMeasured($group, $pageCount)) {
            return null;
        }

        $inGroup = $issues->filter(fn (SiteAuditIssue $issue): bool => $this->groupOf($issue) === $group);

        $sitePenalty = 0.0;
        $pagePenalties = [];

        foreach ($inGroup as $issue) {
            $penalty = (float) $issue->severity->penalty();

            if ($issue->site_audit_page_id === null) {
                $sitePenalty += $penalty * self::SITE_FINDING_MULTIPLIER;

                continue;
            }

            $pagePenalties[$issue->site_audit_page_id] = ($pagePenalties[$issue->site_audit_page_id] ?? 0.0) + $penalty;
        }

        // The mean over every page this group *looked at*, not only the ones
        // with findings — a site where two pages of a hundred are broken is a
        // healthy site, and averaging the two would report it as broken.
        $scored = 0.0;

        foreach ($pagePenalties as $penalty) {
            $scored += max(0.0, 100.0 - $penalty);
        }

        $clean = max(0, $pageCount - count($pagePenalties));

        $pageAverage = $pageCount > 0
            ? ($scored + ($clean * 100.0)) / $pageCount
            : 100.0;

        return (int) round(max(0.0, min(100.0, $pageAverage - $sitePenalty)));
    }

    /**
     * How many pages this group's checks were actually run against.
     *
     * The denominator, and getting it wrong is the single easiest way to make
     * this whole screen lie. Ninety of a hundred sitemap URLs answering 404 is
     * a common shape after a site restructure; those ninety pages run only
     * {@see InspectPages}'s indexability check, so they have no title to be
     * missing and no image to be unsized. Divided by a hundred, the ninety
     * count as clean and "LLM optimization" comes out at 99 for a site that is
     * ninety per cent dead — the inverse of the truth, and reported with
     * confidence.
     *
     * So a group is averaged over the pages its own checks looked at: the
     * readable ones, plus the unreadable ones only for the group indexability
     * belongs to. {@see CheckRegistry::groupRunsOn()} is the one place that
     * answers "did this group run here", shared with the step that inspects, so
     * the two cannot drift apart again.
     */
    private function pagesLookedAt(AuditCheckGroup $group, int $readable, int $unreadable): int
    {
        $pages = $this->checks->groupRunsOn($group, readable: true) ? $readable : 0;

        if ($this->checks->groupRunsOn($group, readable: false)) {
            $pages += $unreadable;
        }

        return $pages;
    }

    /**
     * Did anything in this group run?
     *
     * A site check always counts: the signals are read whether or not the files
     * they look for exist, so "no robots.txt" is a measurement rather than a
     * gap. A group made only of page checks needs a page to have looked at.
     */
    private function wasMeasured(AuditCheckGroup $group, int $pagesLookedAt): bool
    {
        if ($pagesLookedAt > 0) {
            return true;
        }

        foreach ($this->checks->all() as $check) {
            if ($check instanceof SiteCheck && $check->group() === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which group an issue belongs to.
     *
     * From the check that raised it, so a check moving between groups moves its
     * history with it. A key this build no longer knows lands in SEO — it has
     * to score somewhere, and the alternative is a finding that is visible on
     * the screen and invisible in the number beside it.
     */
    private function groupOf(SiteAuditIssue $issue): AuditCheckGroup
    {
        return $this->checks->find($issue->check_key)?->group() ?? AuditCheckGroup::Seo;
    }

    /**
     * The weighted mean of the groups that were measured.
     *
     * @param  array<string, int|null>  $groups
     */
    private function headline(array $groups): ?int
    {
        $total = 0.0;
        $weight = 0.0;

        foreach (AuditCheckGroup::cases() as $group) {
            $score = $groups[$group->value] ?? null;

            if ($score === null) {
                continue;
            }

            $total += $score * $group->weight();
            $weight += $group->weight();
        }

        // Renormalised by the weight actually used, so dropping an unmeasured
        // group re-scales the rest instead of scoring them out of a smaller
        // total. Without this, a site with no PageSpeed key could not exceed 80.
        return $weight > 0.0 ? (int) round($total / $weight) : null;
    }
}
