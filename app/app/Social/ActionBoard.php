<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\ContentItemState;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * One month of social work, in the three states a person cares about.
 *
 * **Derived, never stored.** The columns are a reading of
 * {@see ContentItemState}, which is already the engine's lifecycle and is
 * already enforced by the state machine. A `board_state` column would be a
 * second lifecycle running beside the real one, and the two would disagree the
 * first time a pipeline moved an item without going through this class — which
 * is most of the time, because most of what moves an item is a worker.
 *
 * So the mapping is stated once, here, and it is deliberately coarse:
 *
 * - **To Do** — nothing has been written. The idea exists and no channel of it
 *   has a body yet.
 * - **In Progress** — something is being made or is waiting for a person.
 *   Queued, generating, drafted, and — importantly — *partly* done: an idea
 *   with one approved channel and one still drafting belongs here, because the
 *   operator's job on it is not finished.
 * - **Done** — every channel of the idea is approved or published, and there is
 *   nothing left for anybody to do.
 *
 * `Refreshing` counts as in progress: the feedback loop is rewriting a body,
 * and a rewritten body is an unreviewed body that returns to `Draft`.
 *
 * **Ideas, not items, are the cards.** The unit an operator thinks in is "the
 * thing we are saying on Tuesday", and its Threads and Instagram versions are
 * two executions of one decision. A board of items would show that Tuesday
 * twice and make the count of work look like the count of rows.
 *
 * @phpstan-type BoardCard array{
 *     id: string,
 *     column: string,
 *     date: string,
 *     title: string,
 *     kind: string,
 *     kind_label: string,
 *     pillar: string,
 *     thesis: string,
 *     content_format: string,
 *     content_format_label: string,
 *     format_chosen: bool,
 *     channels: list<string>,
 *     production: array<string, array{format: string, visual: string}>,
 *     drafted: int,
 *     planned: int,
 *     items: list<array{id: string, channel: string, state: string}>,
 * }
 */
final class ActionBoard
{
    public const string TODO = 'todo';

    public const string IN_PROGRESS = 'in_progress';

    public const string DONE = 'done';

    /**
     * The month's ideas, each with the column it belongs in.
     *
     * One query for the ideas and one for their items. The alternative — an
     * `->contentItems` per card — is the lazy load this application refuses on
     * purpose, and on a forty-idea month it is forty queries to render a board.
     *
     * @return Collection<int, BoardCard>
     */
    public static function cards(ContentPlan $plan): Collection
    {
        /** @var EloquentCollection<int, ContentIdea> $ideas */
        $ideas = $plan->contentIdeas()
            ->where('proposal_version', $plan->assistant_version)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();

        $itemsByIdea = ContentItem::query()
            ->whereIn('content_idea_id', $ideas->modelKeys())
            ->get(['id', 'content_idea_id', 'channel_type', 'state', 'title', 'scheduled_for'])
            ->groupBy(static fn (ContentItem $item): string => (string) $item->content_idea_id);

        /** @var Collection<int, BoardCard> $cards */
        $cards = $ideas->map(static function (ContentIdea $idea) use ($itemsByIdea): array {
            /** @var Collection<int, ContentItem> $items */
            $items = $itemsByIdea->get((string) $idea->getKey(), collect());

            return [
                'id' => (string) $idea->getKey(),
                'column' => self::columnFor($idea, $items),
                'date' => $idea->scheduled_for->toDateString(),
                'title' => $idea->title,
                'kind' => $idea->kind->value,
                'kind_label' => $idea->kind->label(),
                'pillar' => $idea->pillar,
                // The rationale, which is what the reference puts on the card
                // and what makes a To Do item something an operator can accept
                // or reject rather than merely execute.
                'thesis' => $idea->thesis,
                // What it will be made as, and whether a person said so or the
                // kind merely implies it. The shelf shows the chip; the panel
                // behind the card lets somebody disagree with it.
                'content_format' => $idea->format()->value,
                'content_format_label' => $idea->format()->label(),
                'format_chosen' => $idea->content_format !== null,
                'channels' => $idea->channels,
                'production' => $idea->plannedProduction(),
                'drafted' => $items->count(),
                'planned' => count($idea->channels),
                'items' => $items->map(static fn (ContentItem $item): array => [
                    'id' => (string) $item->getKey(),
                    'channel' => $item->channel_type,
                    'state' => $item->state->value,
                ])->values()->all(),
            ];
        });

        return $cards;
    }

    /**
     * How many of the month's posts have been signed off.
     *
     * Items rather than ideas, and approved-or-published rather than published
     * alone. An operator who has approved everything has done the whole of
     * their job; whether a delivery window has come round is the engine's
     * business and not a reason to show them 40%.
     *
     * @return array{shipped: int, planned: int}
     */
    public static function progress(?ContentPlan $plan, int $planned): array
    {
        if ($plan === null) {
            return ['shipped' => 0, 'planned' => $planned];
        }

        $shipped = ContentItem::query()
            ->where('content_plan_id', $plan->getKey())
            ->whereNotNull('content_idea_id')
            ->whereIn('state', [
                ContentItemState::Approved->value,
                ContentItemState::Published->value,
            ])
            ->count();

        return ['shipped' => $shipped, 'planned' => $planned];
    }

    /**
     * @param  Collection<int, ContentItem>  $items
     */
    private static function columnFor(ContentIdea $idea, Collection $items): string
    {
        if ($items->isEmpty()) {
            return self::TODO;
        }

        // A channel the plan asked for and nothing has written is unfinished
        // work, whatever the written ones say. Without this an idea whose only
        // drafted channel got approved would sit in Done with an Instagram post
        // that never existed.
        if ($items->count() < count($idea->channels)) {
            return self::IN_PROGRESS;
        }

        $settled = $items->every(static fn (ContentItem $item): bool => in_array(
            $item->state,
            [ContentItemState::Approved, ContentItemState::Published],
            true,
        ));

        return $settled ? self::DONE : self::IN_PROGRESS;
    }
}
