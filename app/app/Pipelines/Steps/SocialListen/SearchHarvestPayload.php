<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Integrations\Threads\ThreadsSearchOutcome;
use App\Pipelines\Contracts\StepPayload;

/**
 * What `keyword_search` handed back this hour (§4.1, §11.2).
 *
 * `degraded` travels with the posts rather than being inferred from them, and
 * that is the whole reason this is a payload and not a list. §11.2 gives three
 * answers that look identical on the wire, {@see ThreadsSearchOutcome} keeps
 * them apart inside the adapter, and a payload carrying only posts would throw
 * that distinction away at the first step boundary — after which "these are our
 * own posts because the scope was never approved" is unrecoverable, and §1's
 * "чужие разговоры, а не свои посты" becomes the engine listening to itself at
 * full weight.
 */
final readonly class SearchHarvestPayload implements StepPayload
{
    /**
     * @param  list<array<string, mixed>>  $posts  each carrying the term and mode that found it
     * @param  list<string>  $terms  what was actually searched for
     */
    public function __construct(
        public array $posts,
        public array $terms,
        public bool $degraded,
        public bool $budgetSpent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'posts' => $this->posts,
            'terms' => $this->terms,
            'degraded' => $this->degraded,
            'budget_spent' => $this->budgetSpent,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array<string, mixed>> $posts */
        $posts = array_values(array_filter(
            is_array($data['posts'] ?? null) ? $data['posts'] : [],
            is_array(...),
        ));

        return new self(
            posts: $posts,
            terms: array_values(array_map(strval(...), is_array($data['terms'] ?? null) ? $data['terms'] : [])),
            degraded: (bool) ($data['degraded'] ?? false),
            budgetSpent: (bool) ($data['budget_spent'] ?? false),
        );
    }
}
