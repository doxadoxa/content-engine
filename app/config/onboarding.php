<?php

declare(strict_types=1);

return [
    /* How long to wait for somebody else's website. */
    'timeout' => (int) env('ONBOARDING_TIMEOUT', 15),

    /* How much page copy reaches the prompt. A footer is mostly navigation. */
    'text_limit' => (int) env('ONBOARDING_TEXT_LIMIT', 6000),

    /*
    | What a new project starts with. Deliberately modest: §1 makes publishing
    | cadence the mitigation for scaled-content risk, so a project ramps up
    | after somebody has read what it produced rather than starting at volume.
    */
    'defaults' => [
        'weekly_target' => 3,
        'derivative_channels' => ['linkedin', 'x'],
    ],
];
