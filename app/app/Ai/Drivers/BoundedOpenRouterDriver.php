<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Drivers\OpenAi\OpenRouter;

/** OpenRouter, with a deadline. */
class BoundedOpenRouterDriver extends OpenRouter
{
    use BoundsOpenAiCompatibleClient;
}
