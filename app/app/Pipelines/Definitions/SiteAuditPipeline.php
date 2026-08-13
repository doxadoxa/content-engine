<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Onboarding\ProjectLaunch;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Audit\CrawlPages;
use App\Pipelines\Steps\Audit\InspectPages;
use App\Pipelines\Steps\Audit\MeasureSpeed;
use App\Pipelines\Steps\Audit\ReadSiteSignals;
use App\Pipelines\Steps\Audit\ScoreAudit;
use App\Pipelines\Steps\Audit\VerifyLinks;

/**
 * What the project's own site looks like to a crawler.
 *
 *                        ┌─→ inspect_pages ─┐
 *   read_site_signals ─→ crawl_pages ─→ verify_links ─┼─→ score_audit
 *                        └─→ measure_speed ─┘
 *
 * The three middle branches are the shape worth stating. They read the same
 * crawled pages and write to different tables, so nothing orders them and the
 * runner dispatches all three the moment the crawl settles — which matters
 * because they are wildly unlike each other in cost: inspection is arithmetic
 * over stored facts and finishes in a second, link verification is hundreds of
 * bounded HEAD requests, and a PageSpeed sweep is a vendor taking most of a
 * minute per page. Sequenced, a sweep would take as long as all three; as a
 * fan-out it takes as long as the slowest.
 *
 * **Its own queue, and this is the request the feature was asked for.** Every
 * step here runs on `pipeline-audit`, which has its own Horizon supervisor and
 * its own process cap. A crawl is dozens of slow outbound requests against
 * somebody else's server, and on the shared cheap queue it would sit in front
 * of every quick step of every generation run in the installation — an article
 * waiting on a customer's TLS handshake. §3.2 separates cheap from expensive
 * for precisely this reason; this is a third kind, slow but not costly, and it
 * gets a third pool rather than being squeezed into whichever of the two fits
 * least badly.
 *
 * **Its own contour, too.** `EngineTickCommand::CONTOUR` lists the six
 * pipelines that feed each other, and this is deliberately not among them: an
 * audit produces nothing the planner reads, so a project halfway through one
 * has exactly as much reason to draft an article as an idle one, and a
 * ten-minute crawl must not stall an hour of writing. The same rule keeps a
 * running audit from holding a launching project's spinner on — see
 * {@see ProjectLaunch::LAUNCH_PIPELINES}.
 */
class SiteAuditPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'site_audit';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Site audit (what the site looks like to a crawler)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            ReadSiteSignals::class,
            CrawlPages::class,
            InspectPages::class,
            VerifyLinks::class,
            MeasureSpeed::class,
            ScoreAudit::class,
        ];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            // Overrides for a sweep started by hand. A sweep started by the
            // scheduler or by the launch passes none of them and takes the
            // configured defaults.
            'max_pages' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'max_links' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'measure_speed' => ['sometimes', 'boolean'],
        ];
    }
}
