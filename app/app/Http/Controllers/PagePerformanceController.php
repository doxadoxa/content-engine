<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Feedback\Measurements\PagePerformance;
use App\Feedback\Measurements\SiteSearchReport;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Feedback\Measurements\SyncSiteSearchJob;
use App\Integrations\Google\GooglePanel;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\SiteSearchTopRow;
use App\Pages\PageUrl;
use App\Pages\TrackedPages;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class PagePerformanceController extends Controller
{
    public function index(CurrentProject $current, PagePerformance $performance, GooglePanel $google, SiteSearchReport $siteSearch): Response
    {
        $project = $current->get();
        abort_if($project === null, 404);

        $connected = $google->connectionState($project)['search_console'];
        // Self-healing, for projects that connected before site-wide reads
        // existed and for a switch whose read the previous one blocked. Once
        // per remembered request, not once per poll: see needsFirstRead().
        if ($siteSearch->needsFirstRead($project)) {
            SyncSiteSearchJob::request($project);
        }
        $report = $performance->forProject($project);
        $dataMode = 'current';
        if (! $connected && ! $this->hasSearchMeasurements($report)) {
            $savedWindow = $performance->latestRecordedWindow($project);
            if ($savedWindow !== null) {
                $report = $performance->forProject($project, $savedWindow);
                $dataMode = 'saved';
            } else {
                $dataMode = 'none';
            }
        } elseif (! $connected) {
            $dataMode = 'saved';
        }

        return Inertia::render('performance/index', [
            'report' => $report,
            'search_connected' => $connected,
            'search_data_mode' => $dataMode,
            'site_search' => $siteSearch->forProject($project),
        ]);
    }

    public function read(CurrentProject $current, GooglePanel $google): RedirectResponse
    {
        $project = $current->get();
        abort_if($project === null, 404);
        abort_unless($project->status === ProjectStatus::Active, 422, 'Activate this project before reading measurements.');
        // The whole property whenever there is one to read; tracked pages are
        // an addition to it, no longer a prerequisite. With neither, there is
        // nothing to queue, and "queued" would be a promise nothing keeps.
        $site = $google->connectionState($project)['search_console'];
        $pages = SitePage::query()->tracked()->exists();
        abort_unless($site || $pages, 422, 'Connect Google Search Console and choose a property, or track a page, before reading measurements.');
        if ($site) {
            SyncSiteSearchJob::request($project);
        }
        if ($pages) {
            SyncPageMeasurementsJob::dispatch($project->id);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Measurement queued. This page updates as the reports arrive.']);

        return to_route('performance.index');
    }

    /**
     * Start monitoring one of the property's top pages.
     *
     * Only a URL Search Console itself reported for this project is accepted.
     * The form is a list of rows we stored, but the request is whatever the
     * client sends, and tracking fetches the page — an arbitrary URL here would
     * be a way to make this server read somebody else's site.
     */
    public function monitor(Request $request, CurrentProject $current, TrackedPages $pages): RedirectResponse
    {
        $project = $current->get();
        abort_if($project === null, 404);
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2000']]);
        // Every refusal lands on `url`: that is the field the top-pages list
        // shows errors under, and an error keyed to a field the form does not
        // have is an error nobody sees.
        if (! SyncSiteSearchJob::eligible($project)) {
            throw ValidationException::withMessages(['url' => 'This project is paused. Resume it before monitoring more pages.']);
        }
        $wanted = $this->normalized($data['url']);
        $known = $wanted !== null && SiteSearchTopRow::query()->where('kind', 'page')->pluck('value')
            ->contains(fn (string $value): bool => $this->normalized($value) === $wanted);
        if (! $known) {
            throw ValidationException::withMessages(['url' => 'Choose a page from your top pages in Search Console.']);
        }
        try {
            // A plan-limit refusal is already keyed to `url` and propagates
            // with its own message.
            $pages->track($project, $data['url'], $this->locale($project, $data['url']), 'other', preferDeclaredLocale: true);
        } catch (UnsafePublicUrl|ConnectionException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => 'The page could not be safely read: '.$e->getMessage()]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (array_keys($errors) === ['url']) {
                throw $e;
            }
            throw ValidationException::withMessages(['url' => collect($errors)->flatten()->first() ?? 'The page could not be monitored.']);
        }
        SyncPageMeasurementsJob::dispatch($project->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Page added to monitored pages.']);

        return to_route('performance.index');
    }

    /**
     * The page's language, read from its path: `/pt/servicos` on a project
     * publishing `pt-PT` is Portuguese. An exact locale wins over a language
     * match, and anything else is the project's default — a page with no
     * language segment is, on most sites, the default language.
     */
    private function locale(Project $project, string $url): string
    {
        $locales = array_values(array_unique([$project->default_locale, ...$project->locales]));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segment = strtolower(explode('/', ltrim($path, '/'))[0]);
        if ($segment === '') {
            return $project->default_locale;
        }
        foreach ($locales as $locale) {
            if (strtolower($locale) === $segment) {
                return $locale;
            }
        }
        foreach ($locales as $locale) {
            if (strtolower(strtok($locale, '-_') ?: $locale) === $segment) {
                return $locale;
            }
        }

        return $project->default_locale;
    }

    private function normalized(string $url): ?string
    {
        try {
            return PageUrl::normalize($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $report */
    private function hasSearchMeasurements(array $report): bool
    {
        foreach ($report['pages'] as $page) {
            foreach (['current', 'previous'] as $period) {
                if ($page[$period]['search']['impressions'] !== null || $page[$period]['queries'] !== []) {
                    return true;
                }
            }
        }

        return false;
    }
}
