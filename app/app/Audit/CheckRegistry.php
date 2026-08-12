<?php

declare(strict_types=1);

namespace App\Audit;

use App\Audit\Checks\IndexabilityCheck;
use App\Audit\Contracts\Check;
use App\Audit\Contracts\PageCheck;
use App\Audit\Contracts\SiteCheck;
use App\Audit\Exceptions\InvalidCheck;
use App\Enums\AuditCheckGroup;

/**
 * The checks this build runs, by key.
 *
 * Keyed rather than class-named for the reason {@see
 * \App\Pipelines\Core\PipelineRegistry} is: `check_key` is on every issue row
 * and has to outlive a class rename, and a check that is deleted should leave
 * its old findings readable rather than fatal on the screen that lists them.
 *
 * Resolved through the container, so a check may take collaborators in its
 * constructor like any other object here.
 */
class CheckRegistry
{
    /** @var array<string, Check>|null */
    private ?array $checks = null;

    /** @return array<string, Check> */
    public function all(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        $checks = [];

        /** @var list<class-string<Check>> $classes */
        $classes = config('audit.checks', []);

        foreach ($classes as $class) {
            $check = app($class);

            if (! $check instanceof Check) {
                throw new InvalidCheck("`{$class}` is registered in config/audit.php but is not a Check.");
            }

            $checks[$class::key()] = $check;
        }

        return $this->checks = $checks;
    }

    public function find(string $key): ?Check
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * A check's own label, or the raw key if this build no longer has it.
     *
     * The fallback is the point: an issue row written by last week's build must
     * still render after its check is removed, and a missing array key on the
     * screen is not the way to find out that it was.
     */
    public function labelFor(string $key): string
    {
        return $this->find($key)?->label() ?? $key;
    }

    /** @return list<SiteCheck> */
    public function siteChecks(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Check $check): bool => $check instanceof SiteCheck,
        ));
    }

    /** @return list<PageCheck> */
    public function pageChecks(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (Check $check): bool => $check instanceof PageCheck,
        ));
    }

    /**
     * The page checks worth running against a page in this state.
     *
     * A page that answered 404 has no title, no headings and no structured
     * data, so running the rest over it produces a column of findings that are
     * all restatements of the first one. It gets {@see IndexabilityCheck} and
     * nothing else.
     *
     * Here rather than in the step that inspects, because {@see AuditScore}
     * needs the same answer for a different reason: a group whose checks never
     * ran against a page must not count that page as a clean one. The two used
     * to disagree — the inspector skipped, the scorer counted — and a site
     * where ninety pages of a hundred were dead came out at 99 for page speed.
     *
     * @return list<PageCheck>
     */
    public function pageChecksFor(bool $readable): array
    {
        if ($readable) {
            return $this->pageChecks();
        }

        return array_values(array_filter(
            $this->pageChecks(),
            static fn (PageCheck $check): bool => $check::key() === IndexabilityCheck::key(),
        ));
    }

    /**
     * Whether any check in this group runs against a page in this state.
     *
     * What {@see AuditScore} divides by: a group is only averaged over the
     * pages it actually looked at.
     */
    public function groupRunsOn(AuditCheckGroup $group, bool $readable): bool
    {
        foreach ($this->pageChecksFor($readable) as $check) {
            if ($check->group() === $group) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the screen's "Site health checks" panel lists.
     *
     * Generated here rather than written in the frontend, so adding a check
     * cannot leave the two out of step.
     *
     * @return list<array{key: string, label: string, description: string, group: string, group_label: string, scope: string}>
     */
    public function describe(): array
    {
        return array_values(array_map(static fn (Check $check): array => [
            'key' => $check::key(),
            'label' => $check->label(),
            'description' => $check->description(),
            'group' => $check->group()->value,
            'group_label' => $check->group()->label(),
            'scope' => $check instanceof SiteCheck ? 'site' : 'page',
        ], $this->all()));
    }
}
