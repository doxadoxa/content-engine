<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ConversationGateway;
use LarAgent\Tool;

/**
 * The conversation, without the network.
 *
 * Bound over {@see ConversationGateway} in the test environment for the same
 * reason {@see FakeModelGateway} is bound over the one-shot door: the suite
 * must never reach a provider, in any phase, for any test.
 *
 * **It really runs the tools.** A fake that only returned text would leave the
 * most important half of this feature untested — whether asking for an article
 * actually produces one — and would pass just as happily if the callbacks were
 * broken. So a queued turn may name tools to call, and this executes their
 * callbacks exactly as the provider's loop would.
 */
class FakeConversationGateway implements ConversationGateway
{
    /** @var list<ConversationRequest> */
    public array $requests = [];

    /** @var list<array{text: string, tools: list<array{name: string, arguments: array<string, mixed>}>}> */
    private array $queued = [];

    /**
     * The next turn: what it says, and what it reaches for on the way.
     *
     * @param  list<array{name: string, arguments?: array<string, mixed>}>  $tools
     */
    public function willReply(string $text, array $tools = []): self
    {
        $this->queued[] = [
            'text' => $text,
            'tools' => array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'arguments' => $tool['arguments'] ?? [],
                ],
                $tools,
            ),
        ];

        return $this;
    }

    public function converse(ConversationRequest $request): ConversationResponse
    {
        $this->requests[] = $request;

        $turn = array_shift($this->queued) ?? [
            // The default is deliberately a question rather than an action. A
            // fake that acted by default would let a test pass while proving
            // the opposite of the rule the instructions are built on.
            'text' => 'What are you hoping that does for the business?',
            'tools' => [],
        ];

        $byName = [];

        foreach ($request->tools as $tool) {
            $byName[$tool->getName()] = $tool;
        }

        $calls = [];

        foreach ($turn['tools'] as $call) {
            $tool = $byName[$call['name']] ?? null;

            if ($tool === null) {
                $calls[] = [
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'result' => ['ok' => false, 'error' => 'No such tool.'],
                ];

                continue;
            }

            $calls[] = [
                'name' => $call['name'],
                'arguments' => $call['arguments'],
                'result' => $this->run($tool, $call['arguments']),
            ];
        }

        return new ConversationResponse(
            text: $turn['text'],
            toolCalls: $calls,
            provider: 'fake',
            model: 'fake-conversation',
            inputTokens: 12,
            outputTokens: 34,
            latencyMs: 1,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function run(Tool $tool, array $arguments): mixed
    {
        $callback = $tool->getCallback();

        if (! is_callable($callback)) {
            return ['ok' => false, 'error' => 'That tool has no callback.'];
        }

        return $callback(...$arguments);
    }
}
