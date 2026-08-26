<?php

declare(strict_types=1);

namespace App\Ai\Assistant;

use App\Ai\Contracts\ConversationGateway;
use App\Ai\ConversationFailed;
use App\Ai\ConversationRequest;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One turn of the conversation, from typed sentence to persisted transcript.
 *
 * The order here is the whole design. The person's message is written **before**
 * the model is asked anything, so a turn that fails on the wire leaves the
 * question on the screen rather than swallowing it — somebody who typed three
 * paragraphs and got an error should still be looking at their three
 * paragraphs.
 */
final class Assistant
{
    /**
     * How much of the thread the model is shown.
     *
     * Turns, not tokens, because the alternative is counting tokens in the
     * application to guess at a limit the provider enforces — and forty turns
     * of a marketing conversation is already far more context than any single
     * question needs. The transcript on screen keeps everything; this is only
     * what is carried back to the provider.
     */
    private const int CONTEXT_TURNS = 40;

    /**
     * How much of a tool's result is carried back on later turns.
     *
     * The turn that *ran* the tool sees all of it — that is the turn whose
     * answer depends on it. Afterwards the model needs to remember what it
     * found, not to re-read it, and a full replay is paid for again on every
     * turn that follows: `read_content_state` alone returns twenty titles, so a
     * long conversation would carry tens of kilobytes of json into a prompt
     * that is billed by the token, for ever.
     */
    private const int TOOL_RECALL_CHARS = 800;

    public function __construct(
        private readonly ConversationGateway $gateway,
        private readonly MarketingTools $tools,
    ) {}

    public function reply(Project $project, AssistantThread $thread, string $message): AssistantMessage
    {
        $said = trim($message);

        $asked = AssistantMessage::query()->create([
            'assistant_thread_id' => $thread->getKey(),
            'role' => AssistantMessage::USER,
            'body' => $said,
        ]);

        try {
            $response = $this->gateway->converse(new ConversationRequest(
                role: 'assistant',
                instructions: AssistantInstructions::for($project),
                message: $said,
                history: $this->history($thread, $asked),
                tools: $this->tools->all(),
            ));
        } catch (ConversationFailed|AssistantException $e) {
            // Recorded as a turn rather than thrown at the screen. A
            // conversation that loses its thread every time a provider hiccups
            // is not a conversation, and the operator is owed the sentence
            // saying what happened in the place they are already looking.
            $thread->touchConversation();

            // The receipts first, and this is not tidiness. A turn that ran a
            // write tool and then failed on the last leg has already started
            // the work; without these rows the screen says nothing happened,
            // and the obvious response — ask again — starts it twice.
            $done = $e instanceof ConversationFailed ? $e->toolCalls : [];

            return DB::transaction(function () use ($thread, $done, $e): AssistantMessage {
                $this->recordTools($thread, $done);

                return AssistantMessage::query()->create([
                    'assistant_thread_id' => $thread->getKey(),
                    'role' => AssistantMessage::ASSISTANT,
                    'body' => $done === []
                        ? 'I could not finish that just now — '.$e->getMessage()
                        : 'I started the work above, but could not finish writing back — '
                            .$e->getMessage().' Nothing needs doing twice.',
                ]);
            });
        }

        $thread->touchConversation();

        return DB::transaction(function () use ($thread, $response): AssistantMessage {
            // The tools first, in the order they ran, so the transcript reads
            // the way the turn happened: it looked something up, it made
            // something, and then it said what it had done.
            $this->recordTools($thread, $response->toolCalls);

            return AssistantMessage::query()->create([
                'assistant_thread_id' => $thread->getKey(),
                'role' => AssistantMessage::ASSISTANT,
                'body' => $response->text,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
            ]);
        });
    }

    /**
     * The engine's receipts, one row each.
     *
     * @param  list<array{name: string, arguments: array<string, mixed>, result: mixed}>  $calls
     */
    private function recordTools(AssistantThread $thread, array $calls): void
    {
        foreach ($calls as $call) {
            AssistantMessage::query()->create([
                'assistant_thread_id' => $thread->getKey(),
                'role' => AssistantMessage::TOOL,
                'tool_name' => $call['name'],
                'tool_arguments' => $call['arguments'],
                'tool_result' => is_array($call['result']) ? $call['result'] : ['value' => $call['result']],
            ]);
        }
    }

    /**
     * What the model is shown of the thread so far.
     *
     * Tool rows are folded into the assistant's side as a plain statement of
     * what ran and what came back, rather than replayed as provider tool
     * messages. Replaying them would mean this class reconstructing call ids
     * the provider issued and we never stored — and the useful half for the
     * next turn is the *result*, which is what a colleague would remember.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(AssistantThread $thread, AssistantMessage $excluding): array
    {
        $rows = AssistantMessage::query()
            ->where('assistant_thread_id', $thread->getKey())
            ->whereKeyNot($excluding->getKey())
            ->latest()
            ->limit(self::CONTEXT_TURNS)
            ->get()
            ->reverse();

        $history = [];

        foreach ($rows as $row) {
            if ($row->role === AssistantMessage::USER) {
                $history[] = ['role' => 'user', 'content' => (string) $row->body];

                continue;
            }

            if ($row->role === AssistantMessage::TOOL) {
                $history[] = [
                    'role' => 'assistant',
                    'content' => "[ran {$row->tool_name}] ".Str::limit(
                        (string) json_encode($row->tool_result),
                        self::TOOL_RECALL_CHARS,
                    ),
                ];

                continue;
            }

            $history[] = ['role' => 'assistant', 'content' => (string) $row->body];
        }

        return $history;
    }
}
