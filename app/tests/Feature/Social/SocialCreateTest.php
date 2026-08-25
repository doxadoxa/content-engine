<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ContentFormat;
use App\Enums\ContentItemState;
use App\Enums\PostKind;
use App\Enums\SignalKind;
use App\Enums\SignalSource;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\Asset;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\Signal;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a post comes from: the month's ideas, and the world's reasons.
 *
 * The signals half is the only thing in this engine that brings anything in
 * from outside our own website. `signals` has been a real table since phase 12
 * — sources, weights, deduplication, expiry — and had no surface, so nothing a
 * person could see ever came of it.
 */
final class SocialCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 10:00:00');

        $this->app->instance(ImageGenerationProvider::class, new FakeImageGeneration);
        $this->app->instance(
            ModelGateway::class,
            (new FakeModelGateway)->willAnswerUsing(static function ($request): string {
                if ($request->role === 'factcheck') {
                    return 'PASS';
                }

                return (string) json_encode([
                    'segments' => ['A short, publishable observation about the work.'],
                    'caption' => 'A short, publishable observation about the work.',
                    'slides' => [['heading' => 'One', 'body' => 'Do the thing.']],
                    'link' => null,
                    'chain_reason' => null,
                    'visual' => [
                        'subject' => 'a gloved hand on a handle', 'composition' => 'close crop',
                        'action' => 'wiping', 'location' => 'an entryway',
                        'style' => 'documentary', 'light' => 'morning',
                    ],
                ]);
            }),
        );

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->operator);
        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);
    }

    #[Test]
    public function the_shelf_offers_only_the_months_unwritten_ideas(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            $this->idea($plan, 'Not written yet');
            $written = $this->idea($plan, 'Already written');

            ContentItem::factory()->create([
                'content_plan_id' => $plan->getKey(),
                'content_idea_id' => $written->getKey(),
                'channel_type' => 'threads',
                'state' => ContentItemState::Draft,
            ]);
            ContentItem::factory()->create([
                'content_plan_id' => $plan->getKey(),
                'content_idea_id' => $written->getKey(),
                'channel_type' => 'x',
                'state' => ContentItemState::Draft,
            ]);
        });

        $this->get('/social/create?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('social/create')
                ->has('ideas', 1)
                ->where('ideas.0.title', 'Not written yet')
                ->has('signals', 0)
                ->has('kinds', 6)
            );
    }

    /**
     * Six, not the month's whole backlog.
     *
     * A shelf of everything unwritten is a decision nobody makes. The rest are
     * not hidden — they are on the board in date order — and the header says so
     * rather than implying the month holds six ideas.
     */
    #[Test]
    public function the_shelf_offers_six_ideas_and_says_what_it_is_a_slice_of(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            for ($n = 0; $n < 11; $n++) {
                $this->idea($plan, "Idea {$n}");
            }
        });

        $this->get('/social/create?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('ideas', 6)
                ->where('idea_total', 11)
                ->etc()
            );
    }

    /**
     * The format is a choice, and the choice reaches what gets made.
     *
     * It used to be derived and never chosen: a carousel happened only on
     * Instagram and only for a how-to, so the renderer we stood up to draw real
     * panels sat mostly idle and every other post bought a photograph whether
     * it wanted one or not.
     */
    #[Test]
    public function choosing_a_format_changes_what_the_idea_will_be_made_as(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            return $this->idea($plan, 'An opinion that needs no picture');
        });

        // A `take` derives to a single image, because only a how-to derives to
        // a carousel. Nobody has disagreed with that yet.
        app(CurrentProject::class)->run($this->project, function () use ($idea): void {
            $this->assertNull($idea->content_format);
            $this->assertSame(ContentFormat::Image, $idea->format());
            $this->assertSame('image', $idea->plannedProduction()['threads']['visual']);
        });

        $this->patchJson("/studio/ideas/{$idea->getKey()}", [
            'content_format' => 'text',
        ])->assertOk()->assertJsonPath('idea.content_format', 'text');

        app(CurrentProject::class)->run($this->project, function () use ($idea): void {
            $fresh = ContentIdea::query()->whereKey($idea->getKey())->firstOrFail();

            $this->assertSame(ContentFormat::Text, $fresh->format());
            // The whole point of the text case: it spends nothing.
            $this->assertSame('none', $fresh->plannedProduction()['threads']['visual']);
            $this->assertSame('none', $fresh->plannedProduction()['x']['visual']);
        });
    }

    /**
     * A rethought idea does not keep the photograph planned for the old one.
     *
     * The drafting step does not treat a shot as a suggestion: it tells the
     * writer to make *exactly* that picture and not to substitute it. So an
     * edited thesis over a stale shot does not produce a slightly-off image, it
     * produces a faithful photograph of the concept the operator just replaced.
     */
    #[Test]
    public function editing_what_an_idea_says_clears_the_photograph_planned_for_it(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create(['assistant_version' => 1]);
            $idea = $this->idea($plan, 'One professional who knows the home');
            $idea->forceFill(['shot' => 'a key on a clean entrance console'])->save();

            return $idea;
        });

        $this->patchJson("/studio/ideas/{$idea->getKey()}", [
            'thesis' => 'Actually this is about how the arrival window is agreed.',
        ])->assertOk()->assertJsonPath('idea.shot', null);

        app(CurrentProject::class)->run($this->project, function () use ($idea): void {
            $this->assertNull(ContentIdea::query()->whereKey($idea->getKey())->firstOrFail()->shot);
        });
    }

    /** An edit that changes nothing about the meaning keeps it. */
    #[Test]
    public function setting_the_format_leaves_the_photograph_alone(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create(['assistant_version' => 1]);
            $idea = $this->idea($plan, 'A standard you can name');
            $idea->forceFill(['shot' => 'balcony doors moving a sheer curtain'])->save();

            return $idea;
        });

        $this->patchJson("/studio/ideas/{$idea->getKey()}", ['content_format' => 'image'])
            ->assertOk()
            ->assertJsonPath('idea.shot', 'balcony doors moving a sheer curtain');
    }

    /** And an operator who has a better idea for the picture may say so. */
    #[Test]
    public function the_photograph_can_be_written_by_hand(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create(['assistant_version' => 1]);

            return $this->idea($plan, 'What a reset table gives back');
        });

        $this->patchJson("/studio/ideas/{$idea->getKey()}", [
            'thesis' => 'Rewritten, and the picture named in the same breath.',
            'shot' => 'a folded chair against a cleared dining table',
        ])->assertOk()->assertJsonPath('idea.shot', 'a folded chair against a cleared dining table');
    }

    #[Test]
    public function a_text_post_buys_no_pictures(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            return $this->idea($plan, 'Words are enough here');
        });

        $this->patchJson("/studio/ideas/{$idea->getKey()}", ['content_format' => 'text'])
            ->assertOk();

        $this->postJson("/studio/ideas/{$idea->getKey()}/generate")->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function () use ($idea): void {
            $items = ContentItem::query()->where('content_idea_id', $idea->getKey())->get();

            $this->assertCount(2, $items);
            // Written, and illustrated by nothing.
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->body_markdown !== null
                    && $item->body_markdown !== '',
            ));
            $this->assertSame(0, Asset::query()->count());
        });
    }

    /**
     * A format cannot be changed once the posts exist.
     *
     * `plannedProduction()` would promise a carousel over a single drawn
     * photograph, and the screen would be describing an artefact that is not on
     * the row.
     */
    #[Test]
    public function the_format_is_settled_once_the_idea_is_written(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            return $this->idea($plan, 'Already made');
        });

        $this->postJson("/studio/ideas/{$idea->getKey()}/generate")->assertStatus(202);

        $this->patchJson("/studio/ideas/{$idea->getKey()}", ['content_format' => 'carousel'])
            ->assertUnprocessable();

        app(CurrentProject::class)->run($this->project, function () use ($idea): void {
            $this->assertNull(
                ContentIdea::query()->whereKey($idea->getKey())->firstOrFail()->content_format,
            );
        });
    }

    #[Test]
    public function only_the_formats_this_engine_can_make_are_accepted(): void
    {
        $idea = app(CurrentProject::class)->run($this->project, function (): ContentIdea {
            $plan = ContentPlan::factory()->forMonth('2026-08-01')->create([
                'assistant_version' => 1,
            ]);

            return $this->idea($plan, 'Not a reel');
        });

        // There is no video pipeline, so there is no Reel — and the API says so
        // rather than accepting the word and quietly making an image.
        $this->patchJson("/studio/ideas/{$idea->getKey()}", ['content_format' => 'reel'])
            ->assertJsonValidationErrors('content_format');
    }

    #[Test]
    public function only_live_signals_are_offered(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $this->signal('Still worth answering', weight: 80);
            $this->signal('Long expired', weight: 90, expiresAt: '2026-08-01 00:00:00');
            $this->signal('Already used', weight: 95, consumedAt: '2026-08-10 00:00:00');
        });

        $this->get('/social/create?month=2026-08')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // A shelf that offered a trend from March would be worse than
                // an empty one.
                ->has('signals', 1)
                ->where('signals.0.title', 'Still worth answering')
                ->etc()
            );
    }

    /**
     * The loop's whole point: a post that can be traced back to its reason.
     *
     * `content_items.signal_id` is a real column rather than a payload key
     * precisely so `whereNotNull('signal_id')` can answer "what did listening
     * actually produce" — and that only works if the attribution survives the
     * trip through the queue.
     */
    #[Test]
    public function writing_from_a_signal_carries_the_attribution_to_the_posts(): void
    {
        $signal = app(CurrentProject::class)->run(
            $this->project,
            fn (): Signal => $this->signal('Do you tip your cleaner in Lisbon?'),
        );

        $this->postJson('/studio/ideas', [
            'title' => 'Do you tip your cleaner in Lisbon?',
            'thesis' => 'Answering a question people keep asking.',
            'kind' => 'take',
            'date' => '2026-08-20',
            'signal_id' => (string) $signal->getKey(),
        ])->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function () use ($signal): void {
            $items = ContentItem::query()->whereNotNull('signal_id')->get();

            $this->assertCount(2, $items);
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => (string) $item->signal_id === (string) $signal->getKey(),
            ));

            // The kind still decides the channels, signal or no signal.
            $idea = ContentIdea::query()->firstOrFail();
            $this->assertSame(PostKind::Take, $idea->kind);
            $this->assertSame(['threads', 'x'], $idea->channels);
        });
    }

    #[Test]
    public function an_idea_written_without_a_signal_is_attributed_to_nothing(): void
    {
        $this->postJson('/studio/ideas', [
            'title' => 'Just something we wanted to say',
            'thesis' => 'No signal behind it.',
            'kind' => 'take',
            'date' => '2026-08-20',
        ])->assertStatus(202);

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(0, ContentItem::query()->whereNotNull('signal_id')->count());
            $this->assertSame(2, ContentItem::query()->count());
        });
    }

    #[Test]
    public function another_projects_signal_cannot_be_written_from(): void
    {
        $other = Project::factory()->create();

        $signal = app(CurrentProject::class)->run(
            $other,
            fn (): Signal => $this->signal('Theirs, not ours'),
        );

        $this->postJson('/studio/ideas', [
            'title' => 'Borrowing somebody else’s reason',
            'thesis' => 'Should not be possible.',
            'kind' => 'take',
            'date' => '2026-08-20',
            'signal_id' => (string) $signal->getKey(),
        ])->assertJsonValidationErrors('signal_id');

        app(CurrentProject::class)->run($this->project, function (): void {
            $this->assertSame(0, ContentItem::query()->count());
        });
    }

    private function idea(ContentPlan $plan, string $title): ContentIdea
    {
        return $plan->contentIdeas()->create([
            'proposal_version' => 1,
            'idea_key' => str($title)->slug()->value(),
            'title' => $title,
            'kind' => PostKind::Take,
            'pillar' => 'Build in public',
            'thesis' => 'A reason to say it.',
            'evidence' => [],
            'goal' => 'trust',
            'audience' => 'founders',
            'angle' => null,
            'channels' => ['threads', 'x'],
            'scheduled_for' => '2026-08-20',
        ]);
    }

    private function signal(
        string $title,
        int $weight = 50,
        ?string $expiresAt = null,
        ?string $consumedAt = null,
    ): Signal {
        return Signal::factory()->create([
            'kind' => SignalKind::Question,
            'source' => SignalSource::ThreadsKeywordSearch,
            'title' => $title,
            'weight' => $weight,
            'occurred_at' => Carbon::parse('2026-08-13 09:00:00'),
            'expires_at' => $expiresAt === null ? null : Carbon::parse($expiresAt),
            'consumed_at' => $consumedAt === null ? null : Carbon::parse($consumedAt),
        ]);
    }
}
