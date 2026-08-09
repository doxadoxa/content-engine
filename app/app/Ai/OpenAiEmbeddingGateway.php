<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\EmbeddingGateway;
use App\Pipelines\Exceptions\RetryableStepFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The real embedding gateway.
 *
 * Straight HTTP rather than through LarAgent: LarAgent models a conversation,
 * and this is one request returning an array of floats. Wrapping it in an agent
 * would add a history, a session key and a message format to something that
 * has none of those.
 */
class OpenAiEmbeddingGateway implements EmbeddingGateway
{
    public function name(): string
    {
        return 'openai';
    }

    public function dimensions(): int
    {
        return (int) config('models.embeddings.dimensions', 1536);
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        try {
            $response = Http::withToken((string) config('models.embeddings.api_key'))
                ->timeout((int) config('models.embeddings.timeout', 30))
                ->retry(0)
                ->post(rtrim((string) config('models.embeddings.base_url'), '/').'/embeddings', [
                    'model' => (string) config('models.embeddings.model'),
                    'input' => $text,
                    'dimensions' => $this->dimensions(),
                ]);
        } catch (ConnectionException $e) {
            throw new RetryableStepFailure("The embedding provider was unreachable: {$e->getMessage()}", previous: $e);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new RetryableStepFailure("The embedding provider answered {$response->status()}.");
        }

        $response->throw();

        /** @var list<float> $vector */
        $vector = $response->json('data.0.embedding') ?? [];

        return $vector;
    }
}
