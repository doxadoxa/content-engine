<?php

declare(strict_types=1);

use App\Audit\Checks\BrokenLinksCheck;
use App\Audit\Checks\CanonicalUrlCheck;
use App\Audit\Checks\HeadingStructureCheck;
use App\Audit\Checks\ImageOptimizationCheck;
use App\Audit\Checks\IndexabilityCheck;
use App\Audit\Checks\JsonLdSchemaCheck;
use App\Audit\Checks\LlmsTxtCheck;
use App\Audit\Checks\MetaDescriptionCheck;
use App\Audit\Checks\MetaTitleCheck;
use App\Audit\Checks\PageWeightCheck;
use App\Audit\Checks\RobotsTxtCheck;
use App\Audit\Checks\SitemapCheck;
use App\Audit\Checks\SslCertificateCheck;

return [

    /*
    |--------------------------------------------------------------------------
    | The checks
    |--------------------------------------------------------------------------
    |
    | Adding one is a class plus a line here. The order is the order the screen
    | lists them in, so it reads as a story rather than as a class hierarchy:
    | can this site be found at all, is each page addressed correctly, can an
    | assistant read it, and is it fast.
    |
    | Removing one is safe: issues already recorded against it keep their key
    | and the registry falls back to it as a label, so an old sweep stays
    | readable after the check that produced it is gone.
    |
    */

    'checks' => [
        // Findable.
        RobotsTxtCheck::class,
        SitemapCheck::class,
        SslCertificateCheck::class,
        IndexabilityCheck::class,

        // Addressed correctly.
        MetaTitleCheck::class,
        MetaDescriptionCheck::class,
        CanonicalUrlCheck::class,
        BrokenLinksCheck::class,

        // Readable by an assistant.
        LlmsTxtCheck::class,
        JsonLdSchemaCheck::class,
        HeadingStructureCheck::class,

        // Fast.
        PageWeightCheck::class,
        ImageOptimizationCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | The crawl
    |--------------------------------------------------------------------------
    |
    | Every number here is a limit on what this engine does to somebody else's
    | server, and they are deliberately modest. A sweep is not a crawler: it is
    | a sample large enough to tell an operator what is wrong with their site,
    | and a site with four hundred pages has the same three template faults on
    | all of them as it does on the first hundred.
    |
    | `max_pages` is per sweep. The sitemap order is the site's own, which puts
    | the pages a site considers important near the top more often than not.
    |
    */

    'crawl' => [
        'max_pages' => (int) env('AUDIT_MAX_PAGES', 100),
        'timeout' => (int) env('AUDIT_TIMEOUT', 15),
        'user_agent' => env('AUDIT_USER_AGENT', 'ContentEngine/1.0 (+audit)'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Link verification
    |--------------------------------------------------------------------------
    |
    | A hundred pages carry thousands of links between them, and almost all of
    | those are the same navigation repeated. They are deduplicated across the
    | whole sweep before anything is fetched, and then capped — because the
    | remainder is still the largest single source of outbound requests in the
    | product, and an unbounded version of this is a denial of service aimed at
    | a customer.
    |
    | What is missed is not lost: the links are checked in page order, so the
    | next sweep of a site that has been fixed spends its budget somewhere new.
    |
    */

    'links' => [
        'max_checked' => (int) env('AUDIT_MAX_LINKS', 300),
        'timeout' => (int) env('AUDIT_LINK_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | PageSpeed Insights
    |--------------------------------------------------------------------------
    |
    | Optional, and absent on most installations. Without a key the speed score
    | is null rather than zero — see App\Audit\AuditScore, which drops an
    | unmeasured group from the headline instead of scoring the site down for
    | something we did not measure.
    |
    | `max_pages` is small on purpose. Google's free quota is 25,000 requests a
    | day and one query per second per key, and a Lighthouse run takes tens of
    | seconds — so this is a sample of the pages that matter, not a survey. The
    | home page is always one of them.
    |
    | Get a key from the Google Cloud console with the PageSpeed Insights API
    | enabled. Without one the endpoint still answers, but unauthenticated
    | quota is far too small to run a sweep against.
    |
    */

    'page_speed' => [
        'api_key' => env('PAGESPEED_API_KEY'),
        'strategy' => env('PAGESPEED_STRATEGY', 'mobile'),
        'max_pages' => (int) env('PAGESPEED_MAX_PAGES', 5),
        'timeout' => (int) env('PAGESPEED_TIMEOUT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | How often a sweep is due
    |--------------------------------------------------------------------------
    |
    | `audit:sweep` runs daily and starts an audit only for projects whose last
    | one is older than this. Weekly, because the things it measures change when
    | somebody deploys rather than on a schedule, and a daily crawl of an
    | unchanged site is a bill and an imposition for the same answer.
    |
    */

    'refresh_after_days' => (int) env('AUDIT_REFRESH_AFTER_DAYS', 7),

];
