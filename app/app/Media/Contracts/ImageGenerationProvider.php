<?php

declare(strict_types=1);

namespace App\Media\Contracts;

use App\Media\GeneratedImage;

/**
 * Makes a picture that does not exist (§8.3).
 *
 * Deliberately *not* a role on ModelGateway, and the reasons are structural
 * rather than stylistic: this returns a file rather than a string, the call is
 * asynchronous underneath (submit, poll, download), and it is billed per image
 * while `pipeline_steps` counts tokens. A text port stretched over all four
 * would be a port that lies about three of them.
 *
 * `generate()` blocks, and hides the polling. That is a choice with a known
 * cost — the step holds its worker for the half minute the model takes — and it
 * is the right one at this volume: the alternative is splitting every caller
 * into a submit step and a poll step and inventing somewhere to keep the
 * operation id in between.
 */
interface ImageGenerationProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @param  list<string>  $references  local paths the model should look at,
     *                                    which is what keeps a set of images
     *                                    looking like one article
     * @param  array<string, mixed>  $options  size, aspect ratio
     */
    public function generate(string $prompt, array $references = [], array $options = []): GeneratedImage;
}
