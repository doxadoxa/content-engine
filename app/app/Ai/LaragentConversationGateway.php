<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agents\EngineAgent;
use App\Ai\Contracts\ConversationGateway;
use App\Pipelines\Core\PipelineRunner;
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
            // With whatever already ran. LarAgent executes the tools *inside*
            // `respond()`, so a failure on the last leg — the request that asks
            // for the words to say about them — arrives after the work is
            // committed and queued. Throwing the history away here would tell
            // an operator the turn did not finish while an article was being
            // written, and their second ask would write a second one.
            //
            // And with whatever it already spent, by the same argument applied
            // to money: the requests that drove those tool calls were paid for
            // before this one fell over. A meter that only counts turns which
            // came back is a meter a flapping provider walks straight through.
            throw new ConversationFailed(
                "The {$choice->provider} conversation failed: {$e->getMessage()}",
                $this->toolCallsSince($history, $before),
                $e,
                $this->usage($agent, $choice, $startedAt),
            );
        }

        // A turn that came back but reported no usage still happened, so it is
        // described with the model we asked for and a token count of zero —
        // the shape the response has always had. Only the *failing* path can
        // say "nothing was spent", because only there is it ever true.
        $usage = $this->usage($agent, $choice, $startedAt) ?? new ConversationUsage(
            provider: $choice->provider,
            model: $choice->model,
            inputTokens: 0,
            outputTokens: 0,
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
        );

        return new ConversationResponse(
            text: is_string($answer) ? $answer : (string) json_encode($answer),
            toolCalls: $this->toolCallsSince($history, $before),
            provider: $usage->provider,
            model: $usage->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            latencyMs: $usage->latencyMs,
        );
    }

    /**
     * What the turn spent, read off the agent's usage storage.
     *
     * **Every round-trip, not the last one.** A turn is a loop: LarAgent calls
     * the model, runs whatever it reached for, and calls again — appending one
     * `UsageRecord` per call — so a turn that used three tools made four
     * requests. Pricing `getLastUsage()` alone would record the fourth and drop
     * the three before it, which are the *expensive* ones: each carries the
     * whole conversation plus every tool result so far, and the final leg is
     * the leanest of the four. {@see PipelineRunner::meter()}
     * sums a step's model calls for exactly this reason.
     *
     * The storage is safe to aggregate whole because its identity is minted per
     * turn — see the random `chat-` key above — so there is nothing in it but
     * this turn.
     *
     * Null where the provider reported nothing at all, because a failure before
     * the first request really did cost nothing and a zero-cost row next to a
     * zero token count is a claim that a call was made. Where usage *is*
     * reported, missing halves are zero rather than null for the reason
     * {@see ModelResponse} gives: a cost table with holes in it is
     * indistinguishable from one showing cheap turns.
     */
    private function usage(EngineAgent $agent, ModelChoice $choice, float|int $startedAt): ?ConversationUsage
    {
        $storage = $agent->usageStorage();
        $last = $storage?->getLastUsage();

        if ($storage === null || $last === null) {
            return null;
        }

        $totals = $storage->aggregate();

        return new ConversationUsage(
            provider: $choice->provider,
            // The last record's, because every call of one turn is the same
            // model — the agent is built with one — and the last is the one
            // that answered.
            model: $last->modelName ?: $choice->model,
            inputTokens: (int) ($totals['total_prompt_tokens'] ?? 0),
            outputTokens: (int) ($totals['total_completion_tokens'] ?? 0),
            latencyMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
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
