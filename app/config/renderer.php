<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The renderer
|--------------------------------------------------------------------------
|
| A headless-browser service inside the compose network that photographs a
| site for onboarding and the brief's palette reader — see
| App\Onboarding\SiteScreenshot. Absent means not configured, which is a skip
| rather than a failure: a deployment without it still analyses every site,
| only without having seen it.
|
| The timeout is generous because a cold container starts a browser before it
| can draw. It is still far below the job's own, so a wedged renderer fails
| one screenshot rather than the whole analysis.
|
*/

return [

    'url' => env('RENDERER_URL'),

    'timeout' => (int) env('RENDERER_TIMEOUT', 120),

];
