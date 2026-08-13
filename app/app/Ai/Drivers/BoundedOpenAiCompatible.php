<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Drivers\OpenAi\OpenAiCompatible;

/**
 * The generic OpenAI-compatible driver, with a deadline.
 *
 * This one matters more than its obscurity suggests: it is `default_driver` in
 * config/laragent.php, so it is what any provider that does not name a driver
 * of its own gets. A provider added later without a `driver` key would
 * otherwise arrive unbounded and look configured.
 */
class BoundedOpenAiCompatible extends OpenAiCompatible
{
    use BoundsOpenAiCompatibleClient;
}
