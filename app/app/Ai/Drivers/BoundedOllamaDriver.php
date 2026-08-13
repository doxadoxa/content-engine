<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use LarAgent\Drivers\OpenAi\OllamaDriver;

/**
 * Ollama, with a deadline.
 *
 * Local models are the case where a deadline is most likely to be *wrong* —
 * a large model on a cold machine can legitimately take minutes. That is a
 * reason to raise `MODEL_TIMEOUT` for such a deployment, not a reason to have
 * no deadline: a host that has stopped answering hangs exactly as expensively
 * as a remote provider that has.
 */
class BoundedOllamaDriver extends OllamaDriver
{
    use BoundsOpenAiCompatibleClient;
}
