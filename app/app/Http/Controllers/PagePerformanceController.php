<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Feedback\Measurements\PagePerformance;
use App\Feedback\Measurements\SyncPageMeasurementsJob;
use App\Integrations\Google\GooglePanel;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PagePerformanceController extends Controller
{
    public function index(CurrentProject $current, PagePerformance $performance, GooglePanel $google): Response
    {
        $project = $current->get();
        abort_if($project === null, 404);

        $connected = $google->connectionState($project)['search_console'];
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
        ]);
    }

    public function read(CurrentProject $current): RedirectResponse
    {
        $project = $current->get();
        abort_if($project === null, 404);
        abort_unless($project->status === ProjectStatus::Active, 422, 'Activate this project before reading measurements.');
        abort_unless(SitePage::query()->tracked()->exists(), 422, 'Track a website page before reading measurements.');
        SyncPageMeasurementsJob::dispatch($project->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Measurement queued. This page updates as the reports arrive.']);

        return to_route('performance.index');
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
