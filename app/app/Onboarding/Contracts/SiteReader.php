<?php

declare(strict_types=1);

namespace App\Onboarding\Contracts;

use App\Onboarding\SiteSnapshot;

/**
 * Fetches a website and pulls out what an onboarding wizard can use.
 *
 * Behind a port for the usual reason — the suite never reaches the network —
 * and because "read a page" is the one step of onboarding that depends on
 * somebody else's server being up.
 */
interface SiteReader
{
    public function read(string $url): SiteSnapshot;
}
