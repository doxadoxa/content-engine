<?php

declare(strict_types=1);

namespace App\Onboarding\Jobs;

use App\Http\Controllers\BrandBriefController;
use App\Models\Project;
use App\Onboarding\SiteInspection;
use App\Onboarding\SiteScreenshot;
use App\Support\Brand\SitePalette;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Photograph one project's site and count the colours off it.
 *
 * **Why this is a job at all.** It used to happen inside the request, and the
 * arithmetic never worked: {@see SiteScreenshot} launches a browser, waits for
 * webfonts and hero imagery, and is allowed 120 seconds to do it. A request
 * that can hold a PHP worker for two minutes is one operator clicking a button
 * and a whole pool gone on a site that loads slowly — and the operator gets a
 * spinner with nothing behind it, because a synchronous read cannot say "still
 * going" either.
 *
 * **What stays in the controller.** The two refusals that need no browser: a
 * project with no address, and a deployment with no renderer. Both are known
 * before anything is dispatched, and answering them from the queue would make
 * an operator wait to be told something we knew when they clicked.
 *
 * **`$tries = 1`, matching every other job here.** A screenshot that failed
 * failed for a reason the next attempt will meet again — the site is down, the
 * address redirects somewhere else, the renderer is not answering — and the
 * button is right there. A retry ladder nobody published is worse than a person
 * deciding to click again.
 *
 * The timeout is the renderer's own plus room to count the pixels. It has to be
 * stated here rather than left to the worker: the `default` supervisor allows
 * 60 seconds, which is half of what the screenshot alone is permitted, and a
 * job killed mid-browser would leave the reading flag set with nothing coming.
 */
class ReadSitePalette implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $projectId) {}

    public function handle(SiteScreenshot $screenshots, CurrentProject $current): void
    {
        $project = Project::query()->whereKey($this->projectId)->first();

        if ($project === null) {
            return;
        }

        // Under the project's own tenant, for the same reason every other job
        // here does it: a queued job arrives with no project in context, and
        // anything scoped that this or a future step reads would fail closed.
        $current->run($project, function () use ($screenshots, $project): void {
            $url = trim((string) $project->website_url);

            try {
                $site = $screenshots->inspect($url);
            } catch (UnsafePublicUrl $e) {
                // Kept, not cleared. A refusal has learned nothing about the
                // site's colours, so the last good suggestion still stands.
                $this->finish($project, 'error', $e->getMessage());

                return;
            }

            if ($site === null) {
                $this->finish($project, 'error', 'That page could not be opened in a browser.');

                return;
            }

            // What the stylesheet says, and only then what the picture shows.
            // The declared colours are exact and complete where they exist; the
            // census is the answer for a page whose colour lives entirely in
            // imagery, and for one whose stylesheet would not be read.
            $palette = SitePalette::fromDeclared($site->colours) ?? SitePalette::fromPng($site->png);

            // Written either way, and the null case is the one that matters. A
            // read that succeeded and found nothing is an *answer*, so leaving
            // the last suggestion in place would keep offering a colour the
            // engine no longer stands behind — and the operator would have no
            // way to tell the two apart, because a stale swatch looks exactly
            // like a fresh one.
            //
            // The two failures above return before this on purpose: a browser
            // that could not open the page has learned nothing, and forgetting
            // a good suggestion because the site was down for a minute is its
            // own bug.
            if ($palette === null) {
                // Told apart from the failures above, because they are not the
                // same thing and only they are faults. A page that paints no
                // surface and carries no vivid mark has nothing this can count
                // — a fact about the page, and an operator told "no colour"
                // goes looking for a bug they will not find.
                $this->finish(
                    $project,
                    'info',
                    'Read your site, but found no colour it paints or marks with — nothing to suggest.',
                    palette: null,
                    writePalette: true,
                    site: $site,
                );

                return;
            }

            $this->finish(
                $project,
                'success',
                'Read the colours from your site.',
                palette: $palette->toArray(),
                writePalette: true,
                site: $site,
            );
        });
    }

    /**
     * The flag has to come down even when the job dies, or the screen polls for
     * an answer that is never coming. {@see BrandBriefController::isReading()}
     * puts a floor under this with a staleness window, because a worker killed
     * outright never reaches here either.
     */
    public function failed(?Throwable $e): void
    {
        $project = Project::query()->whereKey($this->projectId)->first();

        if ($project === null) {
            return;
        }

        Log::error('Reading a site palette failed', [
            'project' => $this->projectId,
            'error' => $e?->getMessage(),
        ]);

        $this->finish($project, 'error', 'Something went wrong reading your site.');
    }

    /**
     * @param  array{fill: string, ink: string, accent: string|null}|null  $palette
     */
    private function finish(
        Project $project,
        string $type,
        string $message,
        ?array $palette = null,
        bool $writePalette = false,
        ?SiteInspection $site = null,
    ): void {
        $analysis = $project->site_analysis;

        if ($writePalette) {
            $analysis['palette'] = $palette;
        }

        // The rest of what the page declares, kept beside the three the
        // renderers use. A brand has more colours than a panel has slots, and
        // the operator picking between them is better served seeing the set than
        // being handed our arithmetic on it — which is the whole difference
        // between this and the census it replaces.
        //
        // Only on a read that reached the page: the same rule the palette
        // follows, because a swatch row left over from a visit that failed looks
        // exactly like one from a visit that worked.
        if ($site !== null) {
            $analysis['palette_colours'] = $site->swatches();
            $analysis['brand_font'] = $site->font();
        }

        $analysis['palette_reading_at'] = null;
        $analysis['palette_outcome'] = ['type' => $type, 'message' => $message];

        $project->forceFill(['site_analysis' => $analysis])->save();
    }
}
