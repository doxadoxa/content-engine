<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\EngineAgent;
use App\Ai\Contracts\ConversationGateway;
use App\Pipelines\Exceptions\RetryableStepFailure;
use Illuminate\Support\Str;
use LarAgent\Context\SessionIdentity;
use LarAgent\History\InMemoryChatHistory;
use LarAgent\Message;
use LarAgent\Messages\ToolResultMessage;
use Throwable;

/**
 * The real conversational door: LarAgent's tool loop in front of the provider.
 *
 * LarAgent runs the loop itself — it hands the model the tool schemas, executes
 * whatever it calls, feeds the results back, and repeats until the model
 * answers in words. That is the behaviour the whole feature rests on, because
 * it is what lets the *model* decide between asking a question and doing the
 * work. A request that is too vague to act on comes back as a sentence with no
 * tool calls in it, which is exactly what should happen and is not a case this
 * class needs to special-case.
 *
 * **The history is rebuilt per turn, from our own table.** LarAgent can persist
 * its own, and deliberately is not asked to: the transcript is the product's,
 * it is rendered on a screen and queried in SQL, and a second copy living in a
 * cache driver would be the copy that disagrees.
 */
class LaragentConversationGateway implements ConversationGateway
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function converse(ConversationRequest $request): ConversationResponse
    {
        $choice = $this->catalog->resolve($request->role);

        $agent = EngineAgent::for('chat-'.Str::random(16))
            ->usingProvider($choice->provider)
            ->withInstructions($request->instructions)
            ->withModel($choice->model)
            ->trackUsage(true);

        foreach ($request->tools as $tool) {
            $agent = $agent->withTool($tool);
        }

        $history = new InMemoryChatHistory(new SessionIdentity('avyo-assistant', 'chat-'.Str::random(16)));

        foreach ($request->history as $turn) {
            $history->addMessage($turn['role'] === 'assistant'
                ? Message::assistant($turn['content'])
                : Message::user($turn['content']));
        }

        $agent->setChatHistory($history);

        $before = $history->count();
        $startedAt = hrtime(true);

        try {
            $answer = $agent->respond($request->message);
        } catch (Throwable $e) {
            // Same reasoning as the one-shot gateway: LarAgent re-throws the
            // vendor SDK's exception as-is and those carry no status code worth
            // inspecting, so transient is the useful default. The caller shows
            // this to a person rather than retrying it, because a person who
            // just typed a sentence would rather be told than left waiting.
            throw new RetryableStepFailure(
                "The {$choice->provider} conversation failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $usage = $agent->usageStorage()?->getLastUsage();

        return new ConversationResponse(
            text: is_string($answer) ? $answer : (string) json_encode($answer),
            toolCalls: $this->toolCallsSince($history, $before),
            provider: $choice->provider,
            model: $usage === null ? $choice->model : ($usage->modelName ?: $choice->model),
            // Zero rather than null where the provider reported nothing, for
            // the reason `ModelResponse` gives: a cost table with holes in it is
            // indistinguishable from one showing cheap turns.
            inputTokens: $usage === null ? 0 : (int) $usage->promptTokens,
            outputTokens: $usage === null ? 0 : (int) $usage->completionTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * What the model reached for on this turn, read back out of the history.
     *
     * Only messages added after the turn began, so a long thread does not
     * re-report every tool it has ever called as though it had just run.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, result: mixed}>
     */
    private function toolCallsSince(InMemoryChatHistory $history, int $before): array
    {
        $calls = [];
        $messages = $history->getMessages();

        foreach ($messages as $index => $message) {
            if ($index < $before || ! $message instanceof ToolResultMessage) {
                continue;
            }

            $content = $message->getContent();
            $raw = $content === null ? '' : (string) $content;
            $decoded = json_decode($raw, true);

            $calls[] = [
                'name' => $message->getToolName(),
                // LarAgent does not carry the arguments onto the result
                // message. They are on the call that preceded it, and are
                // reported by the tools themselves in their result — which is
                // the more useful half anyway, since it says what was made
                // rather than what was asked for.
                'arguments' => [],
                'result' => $decoded ?? $raw,
            ];
        }

        return $calls;
    }
}
