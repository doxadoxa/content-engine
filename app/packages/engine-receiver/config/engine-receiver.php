<?php

declare(strict_types=1);

return [
    /*
    | The shared secret. The same string the channel holds on the engine side —
    | it never travels, it only signs.
    */
    'secret' => env('ENGINE_RECEIVER_SECRET'),

    /* Where the endpoint is mounted. */
    'path' => env('ENGINE_RECEIVER_PATH', 'engine/webhook'),

    /* How far the two clocks may drift before a signature is refused. */
    'tolerance' => (int) env('ENGINE_RECEIVER_TOLERANCE', 300),

    /*
    | Where a received unit ends up on this site. Returned to the engine as
    | `public_url`, which is what its feedback loop matches GSC data against.
    */
    'public_url' => null,

    /* Route middleware. `api` by default: this is a machine endpoint. */
    'middleware' => ['api'],
];
