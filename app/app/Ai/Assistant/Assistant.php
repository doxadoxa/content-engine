<?php

declare(strict_types=1);

namespace App\Ai\Assistant;

use App\Ai\Contracts\ConversationGateway;
use App\Ai\ConversationFailed;
use App\Ai\ConversationRequest;
use App\Ai\ConversationUsage;
use App\Ai\ModelCatalog;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Project;
use App\Support\Metering\ProjectSpend;
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
        private readonly ModelCatalog $catalog,
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

            // What the broken turn had already spent, if anything. A turn that
            // ran three tools and fell over on the fourth request bought those
            // three, and the row that says so is the apology — there is no
            // other row for this turn to hang its cost on.
            $spent = $e instanceof ConversationFailed ? $e->usage : null;

            return DB::transaction(function () use ($thread, $done, $e, $spent): AssistantMessage {
                $this->recordTools($thread, $done);

                return AssistantMessage::query()->create([
                    'assistant_thread_id' => $thread->getKey(),
                    'role' => AssistantMessage::ASSISTANT,
                    'body' => $done === []
                        ? 'I could not finish that just now — '.$e->getMessage()
                        : 'I started the work above, but could not finish writing back — '
                            .$e->getMessage().' Nothing needs doing twice.',
                    ...$this->meter($spent),
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
                ...$this->meter($response->usage()),
            ]);
        });
    }

    /**
     * What a turn cost, as columns.
     *
     * The same columns a pipeline step carries, under the same names, because
     * the only reason to meter this at all is that one query can then ask what
     * a project has spent — see {@see ProjectSpend} —
     * and two shapes for one question is how such a query comes to sum half of
     * it.
     *
     * An unmeasured turn writes nothing rather than zeroes. Zero tokens at zero
     * cost is a claim that a call was made and was free; the absence of a
     * provider is the claim that no call was made at all, which is what the
     * columns already say by defaulting.
     *
     * Priced at today's list rather than at the thread's, and the version is
     * stored beside the figure. A conversation is not a run: it has no start
     * that a price could be pinned to, and its turns can be days apart.
     *
     * @return array<string, mixed>
     */
    private function meter(?ConversationUsage $usage): array
    {
        if ($usage === null) {
            return [];
        }

        $version = $this->catalog->priceListVersion();

        return [
            'provider' => $usage->provider,
            'model' => $usage->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_micros' => $this->catalog->cost(
                $usage->model,
                $usage->inputTokens,
                $usage->outputTokens,
                $version,
            ),
            'latency_ms' => $usage->latencyMs,
            'price_list_version' => $version,
        ];
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
