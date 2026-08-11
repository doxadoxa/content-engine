<?php

declare(strict_types=1);

namespace App\Media;

use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The door to the service that draws panels.
 *
 * A browser laying out a React component, which is the one way of putting words
 * on a picture that produces the words that were written. The engine forbids
 * text inside generated images because every model garbles it — correct, and it
 * left a teaching carousel with its steps in the caption and an unrelated
 * photograph attached to the post.
 *
 * **Absent is a skip, not a failure.** A deployment with no renderer still
 * writes every post; its carousels are what they were before this existed.
 * Treating a missing service as an error would make an optional capability a
 * required one, which is the shape {@see SocialImage} already takes for an
 * unconfigured image provider.
 *
 * **The two failures are told apart on purpose.** A connection that would not
 * open is a container still bundling or a host that blinked, and the pipeline's
 * retry is exactly right for it. A 500 from a render is a template that throws
 * on these props, and it will throw identically on every retry — spending three
 * attempts to find that out delays the batch and changes nothing.
 */
class PanelRenderer
{
    public function isConfigured(): bool
    {
        return $this->base() !== '';
    }

    /**
     * One panel as PNG bytes.
     *
     * @param  array<string, mixed>  $props
     */
    public function render(string $composition, array $props, int $width, int $height): string
    {
        if (! $this->isConfigured()) {
            throw new TerminalStepFailure('No panel renderer is configured for this deployment.');
        }

        try {
            $response = Http::timeout((int) config('content_studio.renderer.timeout', 120))
                ->acceptJson()
                ->post($this->base().'/render', [
                    'composition' => $composition,
                    'props' => $props,
                    'width' => $width,
                    'height' => $height,
                ]);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure(
                "The panel renderer could not be reached: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->status() >= 500) {
            throw new TerminalStepFailure(
                'The panel renderer could not draw this slide: '
                    .(string) ($response->json('message') ?? $response->body()),
            );
        }

        if (! $response->successful()) {
            throw new TerminalStepFailure("The panel renderer refused the request with {$response->status()}.");
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RetryableStepFailure('The panel renderer answered with an empty image.');
        }

        return $bytes;
    }

    private function base(): string
    {
        return rtrim((string) config('content_studio.renderer.url', ''), '/');
    }
}
