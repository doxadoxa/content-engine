<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Integrations\Feeds\FeedReader;
use App\Pipelines\Contracts\StepPayload;

/**
 * What the project's RSS whitelist gave up this hour (§4.1).
 *
 * `silent` is a list of addresses rather than a count, because it is the only
 * place an operator ever learns that a feed they added has stopped answering.
 * {@see FeedReader} never throws — one publisher must
 * not decide whether the other nineteen are read — and the cost of that promise
 * is that a dead feed is indistinguishable from an empty one unless somebody
 * writes the address down.
 */
final readonly class FeedHarvestPayload implements StepPayload
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $silent  feeds that answered with nothing, for whatever reason
     */
    public function __construct(
        public array $items,
        public array $silent,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items, 'silent' => $this->silent];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter(
            is_array($data['items'] ?? null) ? $data['items'] : [],
            is_array(...),
        ));

        return new self(
            items: $items,
            silent: array_values(array_map(strval(...), is_array($data['silent'] ?? null) ? $data['silent'] : [])),
        );
    }
}
