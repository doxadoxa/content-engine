<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\VisibilityReport;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prompt analysis (§9.3, the GEO half): where the brand shows up in AI answers.
 *
 * Every number here comes from {@see VisibilityReport}, which is also what the
 * dashboard card and the pipeline's own summary read. Three places computing a
 * headline percentage three ways is the sort of disagreement nobody files a bug
 * about and everybody quietly stops trusting the product over.
 */
class VisibilityController extends Controller
{
    public function __invoke(LlmVisibilityGateway $assistants, CurrentProject $current): Response
    {
        $project = $current->get();

        if ($project === null) {
            abort(403);
        }
        $report = VisibilityReport::latest();

        return Inertia::render('visibility/index', [
            'summary' => [
                // Null, not 0. The screen renders the two differently because
                // "you are in none of the answers" and "nothing has been asked
                // yet" are opposite facts that both look like zero.
                'score' => $report->score(),
                'monitored_prompts' => $report->monitoredPrompts(),
                'answered' => $report->answered(),
                'mentions' => $report->mentions(),
                'declined' => $report->declined(),
                'money_spent' => $report->moneySpent(),
                'last_asked_on' => $report->lastAskedOn?->toDateString(),
                'configured' => $assistants->isConfigured(),
            ],
            'by_locale' => $report->byLocale(),
            'by_platform' => $this->labelled($report->byPlatform()),
            'prompts' => $report->promptRows(),
            'sources' => $report->topSources(),
            'competitors' => $report->competitors($project->website_url),
            'project' => [
                'name' => $project->name,
                'locales' => $this->locales($project),
            ],
        ]);
    }

    /**
     * @param  list<array{platform: string, score: float|null, answered: int, mentions: int, last_asked_on: string|null, stale: bool}>  $rows
     * @return list<array{platform: string, label: string, score: float|null, answered: int, mentions: int, last_asked_on: string|null, stale: bool}>
     */
    private function labelled(array $rows): array
    {
        return array_map(static function (array $row): array {
            $label = config("visibility.platforms.{$row['platform']}.label");

            return [...$row, 'label' => is_string($label) ? $label : $row['platform']];
        }, $rows);
    }

    /**
     * Every language this project publishes in, which is also every language it
     * is measured in — the screen shows both together so a locale with prompts
     * and a locale without are distinguishable at a glance.
     *
     * @return list<string>
     */
    private function locales(Project $project): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), [$project->default_locale, ...$project->locales]),
            static fn (string $locale): bool => $locale !== '',
        )));
    }
}
