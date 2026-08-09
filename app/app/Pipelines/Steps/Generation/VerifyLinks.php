<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Check every outside link the article cites, and delete the ones that lie.
 *
 * The draft is asked for authoritative sources, and a model with no web access
 * answers that request by remembering URLs — which is a polite way of saying it
 * guesses some of them. A 404 in a published article is worse than no citation
 * at all: it is a claim of sourcing that falls over the moment anybody checks.
 *
 * So each one is fetched. What survives stays a link; what does not becomes
 * plain text, keeping the sentence and losing the false promise.
 */
class VerifyLinks extends AbstractStep
{
    use ResolvesUnit;

    /** Generous: a slow standards body is still a real source. */
    private const int TIMEOUT = 12;

    /** Past this many, the article is a link farm and not worth the requests. */
    private const int MAX_LINKS = 12;

    public function __construct(private readonly PublicHttpClient $http) {}

    public static function key(): string
    {
        return 'verify_links';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteDraft::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $draft = $context->output(WriteDraft::key(), DraftPayload::class);

        preg_match_all('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/u', $draft->markdown, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return StepResult::skip('The draft cites no outside sources.');
        }

        $markdown = $draft->markdown;
        $kept = 0;
        $dropped = [];

        foreach (array_slice($matches, 0, self::MAX_LINKS) as $match) {
            [$whole, $text, $url] = $match;

            if ($this->resolves($url)) {
                $kept++;

                continue;
            }

            // The words stay, the link goes. Removing the sentence would take
            // out a claim the fact-check step already passed.
            $markdown = str_replace($whole, $text, $markdown);
            $dropped[] = $url;
        }

        if ($dropped !== []) {
            Log::info('Dropped citations that do not resolve', [
                'unit' => $this->unit($context)->slug,
                'dropped' => $dropped,
            ]);
        }

        return StepResult::success(new VerifiedLinksPayload($markdown, $kept, $dropped));
    }

    /**
     * HEAD first, then GET.
     *
     * Plenty of sites answer 405 or 403 to a HEAD and serve the same page
     * perfectly well to a GET, and treating those as dead would strip good
     * citations.
     */
    private function resolves(string $url): bool
    {
        foreach (['head', 'get'] as $method) {
            try {
                $response = $this->http->request(
                    strtoupper($method),
                    $url,
                    ['User-Agent' => 'ContentEngine/1.0 (+link-check)'],
                    self::TIMEOUT,
                    3,
                )->response;
            } catch (ConnectionException|UnsafePublicUrl) {
                continue;
            }

            if ($response->successful()) {
                return true;
            }
        }

        return false;
    }
}
