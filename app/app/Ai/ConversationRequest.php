<?php

declare(strict_types=1);

namespace App\Ai;

use LarAgent\Tool;

/**
 * A turn to take: what has been said so far, what may be reached for, and the
 * new thing somebody just typed.
 *
 * The history is plain rows rather than a provider's message objects, because
 * the application owns the transcript — see `assistant_messages`. Translating
 * it into whatever the provider wants is the gateway's job and nobody else's.
 */
final readonly class ConversationRequest
{
    /**
     * @param  list<array{role: string, content: string}>  $history  oldest first, excluding the new message
     * @param  list<Tool>  $tools
     */
    public function __construct(
        public string $role,
        public string $instructions,
        public string $message,
        public array $history = [],
        public array $tools = [],
    ) {}
}
