<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Drivers\OpenAi\GeminiDriver;

/** Gemini through its OpenAI-compatible endpoint, with a deadline. */
class BoundedGeminiDriver extends GeminiDriver
{
    use BoundsOpenAiCompatibleClient;
}
