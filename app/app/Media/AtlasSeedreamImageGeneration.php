<?php

declare(strict_types=1);

namespace App\Media;

use App\Media\Contracts\ImageGenerationProvider;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Seedream on AtlasCloud (§8.3).
 *
 * Submit, poll, download — the three steps every image vendor has underneath,
 * hidden behind one blocking call because a pipeline step is already a unit of
 * work that can take a while and be retried.
 *
 * Unverified against a live account: there is no AtlasCloud key in this project
 * yet, so the request and response shapes below are written from the vendor's
 * documented API and should be checked against a real call before they are
 * trusted. Everything the suite asserts runs through {@see FakeImageGeneration}.
 */
class AtlasSeedreamImageGeneration implements ImageGenerationProvider
{
    public function name(): string
    {
        return 'atlascloud';
    }

    public function isConfigured(): bool
    {
        return is_string(config('media.atlas.api_key')) && config('media.atlas.api_key') !== '';
    }

    /**
     * @param  list<string>  $references
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $references = [], array $options = []): GeneratedImage
    {
        if (! $this->isConfigured()) {
            throw new TerminalStepFailure('AtlasCloud has no API key configured.');
        }

        // The `edit` model requires at least one reference picture and refuses
        // an empty list, so a first image and a follow-up image are literally
        // different models. References are what keep a set looking like one
        // article rather than eight unrelated illustrations.
        $model = $references === []
            ? (string) config('media.atlas.text_model')
            : (string) config('media.atlas.model');

        $operation = $this->submit($model, $prompt, $references, $options);
        $url = $this->await($operation);

        return $this->download($url, $model, $options);
    }

    /**
     * @param  list<string>  $references
     * @param  array<string, mixed>  $options
     */
    private function submit(string $model, string $prompt, array $references, array $options): string
    {
        // AtlasCloud's own path and body. This was written OpenAI-shaped —
        // `POST /images/generations`, `data.0.url` — from a reading of the docs
        // that was never run against the API, and every call came back 404.
        $response = $this->request()->post($this->url('/model/generateImage'), array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'width' => (int) ($options['width'] ?? 1200),
            'height' => (int) ($options['height'] ?? 630),
            'image' => $references === [] ? null : $references,
        ], static fn (mixed $value): bool => $value !== null));

        $this->guard($response->status(), $response->body());

        $id = $response->json('data.id') ?? $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new RetryableStepFailure('AtlasCloud accepted the request but returned no operation id.');
        }

        return $id;
    }

    private function await(string $operation): string
    {
        $deadline = now()->addSeconds((int) config('media.atlas.ready_timeout', 300));
        $interval = (int) config('media.atlas.poll_seconds', 3);

        while (now()->lessThan($deadline)) {
            $response = $this->request()->get($this->url("/model/prediction/{$operation}"));

            $this->guard($response->status(), $response->body());

            $status = (string) ($response->json('data.status') ?? $response->json('status') ?? '');

            if (in_array($status, ['failed', 'canceled'], true)) {
                // The model refused or could not draw it. Another attempt gets
                // the same refusal, more slowly and for the same money.
                throw new TerminalStepFailure(
                    'AtlasCloud could not produce the image: '
                    .(string) ($response->json('data.error') ?? $response->json('error') ?? 'no reason given')
                );
            }

            // `outputs` is a list of URLs; the first is the picture.
            $url = $response->json('data.outputs.0');

            if (is_string($url) && $url !== '') {
                return $url;
            }

            Sleep::for($interval)->seconds();
        }

        // Still working when the budget ran out. Retryable: the next attempt
        // may well find a less busy queue at the vendor.
        throw new RetryableStepFailure("AtlasCloud did not finish operation {$operation} in time.");
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function download(string $url, string $model, array $options): GeneratedImage
    {
        try {
            $response = Http::timeout((int) config('media.atlas.download_timeout', 300))->get($url);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure("The generated image could not be downloaded: {$e->getMessage()}", previous: $e);
        }

        $response->throw();

        $path = 'generated/'.Str::random(24).'.webp';

        MediaDisk::put($path, $response->body());

        // Measured, not assumed. The provider honours the *ratio* it was asked
        // for and renders on its own grid — a request for 1080×1350 comes back
        // as 1792×2240 — so recording the request wrote a number into every
        // asset row that the file does not have. It went unnoticed while every
        // caller asked for the same 1200×630; it stopped being invisible when
        // each channel started asking for its own crop.
        $measured = @getimagesizefromstring($response->body());

        return new GeneratedImage(
            disk: MediaDisk::name(),
            path: $path,
            width: is_array($measured) ? $measured[0] : (int) ($options['width'] ?? 1200),
            height: is_array($measured) ? $measured[1] : (int) ($options['height'] ?? 630),
            provider: $this->name(),
            model: $model,
            // Per picture, from config: there is no token count to price, and
            // a cost of zero next to a real image would read as free.
            costMicros: (int) config('media.atlas.cost_micros', 40_000),
        );
    }

    private function guard(int $status, string $body = ''): void
    {
        if ($status === 429 || $status >= 500) {
            throw new RetryableStepFailure("AtlasCloud answered {$status}.");
        }

        if ($status >= 400) {
            // The body, trimmed. "Refused with 404" on its own sent us looking
            // at credentials for an hour when the path was simply wrong.
            throw new TerminalStepFailure(trim(
                "AtlasCloud refused the request with {$status}. ".mb_substr($body, 0, 300)
            ));
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('media.atlas.api_key'))
            ->timeout((int) config('media.atlas.timeout', 60))
            ->retry(0);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('media.atlas.base_url'), '/').$path;
    }
}
