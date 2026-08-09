<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap operator
    |--------------------------------------------------------------------------
    |
    | The account the seeder creates so a fresh stack can be signed into. Read
    | through config rather than env() directly in the seeder: production caches
    | the config, at which point env() returns null and the seeder would create
    | an operator with no email at all.
    |
    */

    'admin' => [
        'email' => env('SEED_ADMIN_EMAIL', 'admin@content-engine.test'),
        'name' => env('SEED_ADMIN_NAME', 'Operator'),
        'password' => env('SEED_ADMIN_PASSWORD', 'password'),
    ],

];
