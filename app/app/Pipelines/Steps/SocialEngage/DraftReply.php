<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Enums\SocialBand;
use App\Models\Interaction;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Social\ReplyGuard;

/**
 * Write the answer. Never send it (§4.2).
 *
 * The only model call in this pipeline, and the only expensive thing in the
 * contour. It produces text and hands it on; the end of this path is
 * {@see Interaction::markDrafted()} and a person, because §4.2
 * forbids autopublish here permanently and
 * {@see SocialBand::Conversation::canEverAutopublish()} says so in code.
 *
 * **One candidate, and the prompt is short.** §4.3 spends tokens on a pool of
 * post candidates because a weak post costs weeks of reach. Nothing like that
 * is true of a reply: the operator reads it before it goes anywhere, and the
 * number this contour is judged on is how long they waited.
 *
 * **Length is asked for and then checked anyway.** The instruction below names
 * the platform's limit, which usually works, and {@see ReplyGuard} measures the
 * result, which always does. §4.3 is explicit that the guard is deterministic;
 * a model told to stay under 500 characters is a hope, not a gate.
 *
 * The role is `draft` rather than `utility` — a reply is the brand speaking to
 * a person by name, which is the thing §1 says is worth as much as the posts.
 * Which model plays that role is one line of `config/models.php` and no change
 * here, which is the whole point of roles (§9).
 */
class DraftReply extends AbstractStep
{
    public static function key(): string
    {
        return 'draft_reply';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [LoadConversation::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $conversation = $context->output(LoadConversation::key(), ConversationPayload::class);
        $limit = app(ReplyGuard::class)->textLimit();

        $answer = $context->ask(
            role: 'draft',
            prompt: implode("\n\n", array_filter([
                $conversation->brief === '' ? null : "How this brand sounds:\n{$conversation->brief}",
                $conversation->entities === []
                    ? null
                    : 'What this project is about: '.implode(', ', $conversation->entities),
                $conversation->ourPost === null
                    ? 'This is a conversation on somebody else\'s post. Do not assume we said anything in it.'
                    : "The post of ours they are replying to:\n{$conversation->ourPost}",
                "{$conversation->author} said:\n{$conversation->question}",
                "Write one reply. Under {$limit} characters. No preamble, no sign-off, no quotation "
                    .'marks around it — reply with the text of the reply and nothing else.',
            ])),
            instructions: 'You answer people on social media as this brand. You are talking to one person '
                .'who took the trouble to write to us, so you are specific, you answer what they asked, and '
                .'you sound like a person rather than a support macro. If we do not know something, say so '
                .'rather than guessing. Never invent a price, a figure or a date.',
        );

        return StepResult::success(new ReplyDraftPayload($this->clean($answer->text)));
    }

    /**
     * Strip what a model adds around an answer it was asked for bare.
     *
     * Wrapping quotes are the common one and they are the expensive one: a
     * reply that goes out inside quotation marks reads as the brand quoting
     * somebody, which is the failure §1 names about cross-posting — the wrong
     * register lands worse than the wrong content.
     */
    private function clean(string $text): string
    {
        $trimmed = trim($text);

        $pairs = [['"', '"'], ['“', '”'], ["'", "'"], ['«', '»']];

        foreach ($pairs as [$open, $close]) {
            if (str_starts_with($trimmed, $open) && str_ends_with($trimmed, $close) && mb_strlen($trimmed) > 1) {
                return trim(mb_substr($trimmed, mb_strlen($open), mb_strlen($trimmed) - mb_strlen($open) - mb_strlen($close)));
            }
        }

        return $trimmed;
    }
}
