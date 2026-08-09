<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Models\BrandBrief;
use App\Pipelines\Contracts\StepPayload;

/**
 * Everything the reply is written from, resolved once (§4.2).
 *
 * Assembled by the one cheap step at the head of the run so that the expensive
 * one is a single model call with no database work in front of it. It also
 * makes the run readable after the fact: the row records the exact brief text
 * and the exact question the answer was written against, which is what makes a
 * bad reply diagnosable rather than merely regrettable.
 */
final readonly class ConversationPayload implements StepPayload
{
    /**
     * @param  string  $author  the handle the operator will see on the row
     * @param  string  $question  what they actually said
     * @param  string|null  $ourPost  the post they replied to, when we published it
     * @param  string  $brief  {@see BrandBrief::compileToPrompt()}
     * @param  list<string>  $entities  the project's own vocabulary (§4.1)
     */
    public function __construct(
        public string $interactionId,
        public string $author,
        public string $question,
        public ?string $ourPost,
        public string $brief,
        public array $entities,
        public bool $onOwnPost,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'interaction_id' => $this->interactionId,
            'author' => $this->author,
            'question' => $this->question,
            'our_post' => $this->ourPost,
            'brief' => $this->brief,
            'entities' => $this->entities,
            'on_own_post' => $this->onOwnPost,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $ourPost = $data['our_post'] ?? null;

        return new self(
            interactionId: (string) ($data['interaction_id'] ?? ''),
            author: (string) ($data['author'] ?? ''),
            question: (string) ($data['question'] ?? ''),
            ourPost: is_string($ourPost) && $ourPost !== '' ? $ourPost : null,
            brief: (string) ($data['brief'] ?? ''),
            entities: array_values(array_map('strval', is_array($data['entities'] ?? null) ? $data['entities'] : [])),
            onOwnPost: (bool) ($data['on_own_post'] ?? false),
        );
    }
}
