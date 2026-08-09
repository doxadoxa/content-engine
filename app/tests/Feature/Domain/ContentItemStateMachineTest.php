<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Content\InvalidStateTransition;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criterion 2 of phase 2: a unit walks the whole machine, and every
 * transition that is not on the map throws.
 *
 * The invalid half is covered exhaustively rather than by sampling. There are
 * only 49 ordered pairs of states, and the ones worth being sure about are
 * exactly the ones nobody thinks to write by hand — `idea → published` is
 * obvious, `approved → draft` is the one that quietly un-approves text a human
 * already signed off on.
 */
final class ContentItemStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    /**
     * @return iterable<string, array{ContentItemState, ContentItemState}>
     */
    public static function everyOrderedPair(): iterable
    {
        foreach (ContentItemState::cases() as $from) {
            foreach (ContentItemState::cases() as $to) {
                yield "{$from->value} -> {$to->value}" => [$from, $to];
            }
        }
    }

    #[Test]
    #[DataProvider('everyOrderedPair')]
    public function a_transition_is_taken_when_the_map_allows_it_and_throws_otherwise(
        ContentItemState $from,
        ContentItemState $to,
    ): void {
        $item = ContentItem::factory()->inState($from)->create();

        if (! $from->canTransitionTo($to)) {
            $this->expectException(InvalidStateTransition::class);

            $item->transitionTo($to);

            return;
        }

        $item->transitionTo($to);

        $this->assertSame($to, $item->refresh()->state);
    }

    #[Test]
    public function a_unit_walks_the_whole_path(): void
    {
        $item = ContentItem::factory()->create();

        $this->assertSame(ContentItemState::Idea, $item->state);

        $item->markQueued();
        $this->assertSame(ContentItemState::Queued, $item->state);

        $item->markGenerating();
        $this->assertSame(ContentItemState::Generating, $item->state);

        $item->markDrafted();
        $this->assertSame(ContentItemState::Draft, $item->state);

        $item->approve();
        $this->assertSame(ContentItemState::Approved, $item->state);

        $item->markPublished();
        $this->assertSame(ContentItemState::Published, $item->state);

        $item->startRefresh();
        $this->assertSame(ContentItemState::Refreshing, $item->state);

        // A refresh rewrites the body, so it lands back in review rather than
        // straight onto the site. This is the edge that closes the loop.
        $item->markDrafted();
        $this->assertSame(ContentItemState::Draft, $item->state);

        // …and the walk survives a round trip, not just the in-memory object.
        $this->assertSame(ContentItemState::Draft, $item->fresh()?->state);
    }

    #[Test]
    public function the_message_names_both_states_and_what_was_allowed(): void
    {
        $item = ContentItem::factory()->inState(ContentItemState::Idea)->create();

        try {
            $item->markPublished();
            $this->fail('Expected the transition to be refused.');
        } catch (InvalidStateTransition $e) {
            // A step that trips this in production reports it into a pipeline
            // log, where "invalid transition" alone is not actionable.
            $this->assertStringContainsString('idea', $e->getMessage());
            $this->assertStringContainsString('published', $e->getMessage());
            $this->assertStringContainsString('queued', $e->getMessage());
        }
    }

    #[Test]
    public function a_refused_transition_leaves_the_stored_state_alone(): void
    {
        $item = ContentItem::factory()->inState(ContentItemState::Draft)->create();

        try {
            $item->markPublished();
        } catch (InvalidStateTransition) {
            // expected
        }

        // The guard runs before the assignment, so neither the object nor the
        // row moved. A machine that rejects the write but keeps the new value
        // in memory is worse than no machine: the next save persists it.
        $this->assertSame(ContentItemState::Draft, $item->state);
        $this->assertSame(ContentItemState::Draft, $item->fresh()?->state);
    }

    #[Test]
    public function publishing_stamps_published_at(): void
    {
        $item = ContentItem::factory()->inState(ContentItemState::Approved)->create([
            'published_at' => null,
        ]);

        $item->markPublished();

        $this->assertNotNull($item->fresh()?->published_at);
    }

    #[Test]
    public function no_state_is_a_dead_end(): void
    {
        // A state with nowhere to go is a unit that can never be worked on
        // again, and the only way to find it is to ask.
        foreach (ContentItemState::cases() as $state) {
            $this->assertNotSame(
                [],
                $state->allowedNext(),
                "{$state->value} has no way out.",
            );
        }
    }
}
