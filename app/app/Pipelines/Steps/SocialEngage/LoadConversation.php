<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialEngage;

use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Social\ReplyRouting;
use App\Support\Social\ProjectVocabulary;

/**
 * Read the conversation, the brief and whatever we said first (§4.2).
 *
 * Cheap and on the cheap queue on purpose. Everything the model call needs is
 * resolved here so that {@see DraftReply} is one request to one provider with
 * no database round trips inside it — §4.2 measures this contour in minutes and
 * the expensive queue is the one with the smaller worker pool.
 *
 * **Our own post is fetched when we have it, and not fetched when we do not.**
 * A reply reads as a non-sequitur without the thing it is replying to, and the
 * journal §9 keeps in `channel_payload` already holds the mapping from the
 * platform's root id back to the unit that became it. No API call: reading the
 * thread from Threads would spend from the budget of §2 to re-learn something
 * this database wrote.
 */
class LoadConversation extends AbstractStep
{
    public function __construct(
        private readonly ProjectVocabulary $vocabulary,
        private readonly ReplyRouting $routing,
    ) {}

    public static function key(): string
    {
        return 'load_conversation';
    }

    public function handle(StepContext $context): StepResult
    {
        $id = (string) $context->get('interaction_id');

        $interaction = Interaction::query()->find($id);

        if ($interaction === null) {
            // Terminal. A run started for a conversation that is not in this
            // project — or not there at all — does not improve on a retry, and
            // the tenant scope failing closed is the same answer as a delete.
            throw new TerminalStepFailure("There is no conversation {$id} in this project to reply to.");
        }

        $brief = BrandBrief::activeFor($context->project);

        return StepResult::success(new ConversationPayload(
            interactionId: $interaction->getKey(),
            author: $interaction->author_handle ?? $interaction->author,
            question: $interaction->text,
            ourPost: $this->ourPost($interaction),
            brief: $brief?->compileToPrompt() ?? '',
            entities: $this->vocabulary->entities($context->project),
            onOwnPost: $this->routing->isOnOurOwnPost($interaction),
        ));
    }

    /**
     * The text of the post this conversation hangs off, when we published it.
     *
     * Matched on the delivery journal rather than on a stored link, because the
     * journal is the only record that survives a unit being edited after it
     * went out: `published_root_id` is what the platform assigned, and it never
     * changes.
     */
    private function ourPost(Interaction $interaction): ?string
    {
        $candidates = array_values(array_filter([
            $interaction->root_external_id,
            $interaction->parent_external_id,
        ]));

        if ($candidates === []) {
            return null;
        }

        $unit = ContentItem::query()
            ->whereIn('channel_payload->published_root_id', $candidates)
            ->first();

        if ($unit === null) {
            return null;
        }

        $text = trim((string) ($unit->body_markdown ?? $unit->summary ?? $unit->title));

        return $text === '' ? null : $text;
    }
}
