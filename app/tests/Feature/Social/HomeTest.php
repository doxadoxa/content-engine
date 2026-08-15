<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PostKind;
use App\Http\Middleware\HandleInertiaRequests;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentGoal;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\User;
use App\Social\ActivationChecklist;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Home: the checklist, and the one chip that has an engine behind it.
 *
 * The checklist's locks are the interesting part. The reference product
 * padlocks everything after the current step, which is how a failed Meta
 * connection locks a user out of the rest of the product; here a padlock means a
 * prerequisite that is a fact, so a deployment with no connection at all can
 * still finish most of the list.
 */
final class HomeTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 10:00:00');

        $this->app->instance(ImageGenerationProvider::class, new FakeImageGeneration);

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create(['site_analysis' => []]);
        $this->project->users()->attach($this->operator);
        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);
    }

    #[Test]
    public function a_fresh_project_is_told_what_to_do_first(): void
    {
        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home/index')
                ->has('checklist', 6)
                ->where('checklist.0.key', 'brief')
                ->where('checklist.0.done', false)
                ->where('checklist.0.locked', false)
                // The only real lock on the list: nothing can be approved
                // until something has been written.
                ->where('checklist.4.key', 'approve')
                ->where('checklist.4.locked', true)
                ->whereNot('checklist.4.blocked_by', null)
                // And the divergence that matters — setting a goal is *not*
                // gated behind connecting anything, so this deployment can
                // reach it.
                ->where('checklist.2.key', 'goal')
                ->where('checklist.2.locked', false)
                ->etc()
            );
    }

    #[Test]
    public function each_step_ticks_off_against_a_real_fact(): void
    {
        app(CurrentProject::class)->run($this->project, function (): void {
            $before = collect(ActivationChecklist::for($this->project, Carbon::parse('2026-08-01')))
                ->keyBy('key');

            $this->assertFalse($before['brief']['done']);
            $this->assertFalse($before['goal']['done']);
            $this->assertFalse($before['channel']['done']);

            BrandBrief::factory()->create(['is_active' => true]);
            $this->project->forceFill(['site_analysis' => ['name' => 'Persistence']])->save();
            ContentGoal::factory()->forMonth('2026-08-01')->confirmed()->create();
            Channel::factory()->create([
                'type' => ChannelType::Webhook,
                'is_enabled' => true,
                'verified_at' => now(),
            ]);
            ContentItem::factory()->create([
                'type' => ContentItemType::SocialPost,
                'channel_type' => 'threads',
                'state' => ContentItemState::Approved,
            ]);

            $after = collect(ActivationChecklist::for(
                $this->project->refresh(),
                Carbon::parse('2026-08-01'),
            ))->keyBy('key');

            foreach (['brief', 'analysis', 'goal', 'post', 'approve', 'channel'] as $key) {
                $this->assertTrue($after[$key]['done'], "{$key} should be done");
                // A finished step never shows an action or a padlock — the
                // button would be an invitation to redo something.
                $this->assertNull($after[$key]['action']);
                $this->assertFalse($after[$key]['locked']);
            }
        });
    }

    /**
     * The chip's whole point: an idea that did not come from a monthly model
     * call, drafting within the second.
     */
    #[Test]
    public function a_typed_idea_becomes_drafts_immediately(): void
    {
        $this->fakeDrafting();

        $this->postJson('/studio/ideas', [
            'title' => 'The van broke down and we still finished on time',
            'thesis' => 'Reliability is a system, not a promise.',
            'kind' => 'behind',
            'date' => '2026-08-20',
        ])
            ->assertStatus(202)
            ->assertJsonPath('operation.action', 'generate_idea');

        app(CurrentProject::class)->run($this->project, function (): void {
            $idea = ContentIdea::query()->firstOrFail();

            $this->assertSame(PostKind::Behind, $idea->kind);
            // The kind decides the channels here exactly as it does in a
            // proposal — a hand-written idea must not be able to do the one
            // thing every planned idea is forbidden.
            $this->assertSame(['instagram', 'threads'], $idea->channels);
            $this->assertSame('2026-08-20', $idea->scheduled_for->toDateString());

            $items = ContentItem::query()->where('content_idea_id', $idea->getKey())->get();
            $this->assertCount(2, $items);
            $this->assertTrue($items->every(
                static fn (ContentItem $item): bool => $item->state === ContentItemState::Draft,
            ));

            // A plan for the month is made if there was not one, so a typed
            // idea does not require a proposal to exist first.
            $plan = ContentPlan::query()->firstOrFail();
            $this->assertSame('2026-08', $plan->month->format('Y-m'));
            $this->assertSame(1, $idea->proposal_version);
        });
    }

    #[Test]
    public function two_typed_ideas_with_the_same_title_do_not_collide(): void
    {
        $this->fakeDrafting();

        foreach ([1, 2] as $ignored) {
            $this->postJson('/studio/ideas', [
                'title' => 'Same title',
                'thesis' => 'Same point.',
                'kind' => 'take',
                'date' => '2026-08-20',
            ])->assertStatus(202);
        }

        app(CurrentProject::class)->run($this->project, function (): void {
            // `idea_key` is what a refinement matches a frozen idea on, so two
            // sharing one would make it keep the wrong one.
            $keys = ContentIdea::query()->pluck('idea_key')->all();

            $this->assertCount(2, $keys);
            $this->assertSame($keys, array_unique($keys));
        });
    }

    #[Test]
    public function a_typed_idea_is_refused_without_a_point_or_a_real_kind(): void
    {
        $this->postJson('/studio/ideas', [
            'title' => 'A title on its own',
            'thesis' => '',
            'kind' => 'take',
            'date' => '2026-08-20',
        ])->assertJsonValidationErrors('thesis');

        $this->postJson('/studio/ideas', [
            'title' => 'A title',
            'thesis' => 'A point.',
            'kind' => 'whatever-i-like',
            'date' => '2026-08-20',
        ])->assertJsonValidationErrors('kind');
    }

    #[Test]
    public function home_counts_what_is_waiting_without_leaking_another_project(): void
    {
        $other = Project::factory()->create();

        app(CurrentProject::class)->run($other, static function (): void {
            ContentItem::factory()->create([
                'type' => ContentItemType::SocialPost,
                'channel_type' => 'threads',
                'state' => ContentItemState::Draft,
            ]);
        });

        app(CurrentProject::class)->run($this->project, static function (): void {
            ContentItem::factory()->count(2)->create([
                'type' => ContentItemType::SocialPost,
                'channel_type' => 'threads',
                'state' => ContentItemState::Draft,
            ]);
        });

        // Deferred, so it arrives on the follow-up request the browser makes
        // rather than in the first render.
        // Asserted on the JSON rather than through `assertInertia`: a partial
        // response carries only the props that were asked for, which is not the
        // whole page object that helper insists on.
        $this->get('/home', $this->partial('waiting'))
            ->assertOk()
            ->assertJsonPath('props.waiting.social_draft_count', 2)
            ->assertJsonCount(2, 'props.waiting.social_drafts')
            ->assertJsonPath('props.waiting.failed_deliveries', 0);
    }

    /**
     * The headers a browser sends when it comes back for a deferred prop.
     *
     * @return array<string, string>
     */
    private function partial(string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'home/index',
            'X-Inertia-Partial-Data' => $props,
        ];
    }

    /** A gateway that answers a usable candidate for any drafting call. */
    private function fakeDrafting(): void
    {
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
                        'subject' => 'a gloved hand on a door handle',
                        'composition' => 'close crop',
                        'action' => 'wiping',
                        'location' => 'an entryway',
                        'style' => 'documentary',
                        'light' => 'morning light',
                    ],
                ]);
            }),
        );
    }
}
