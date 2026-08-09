<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\AssetRole;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineStepStatus;
use App\Enums\SocialBand;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\SocialPlan;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Steps\Repurpose\DerivativeOverlap;
use App\Pipelines\Steps\SocialDraft\DraftCandidates;
use App\Pipelines\Steps\SocialDraft\FactCheckPost;
use App\Pipelines\Steps\SocialDraft\GenerateImage;
use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Pipelines\Steps\SocialDraft\GuardPolicy;
use App\Pipelines\Steps\SocialDraft\LoadContext;
use App\Pipelines\Steps\SocialDraft\RankCandidates;
use App\Pipelines\Steps\SocialDraft\SaveDraft;
use App\Social\Governor;
use App\Support\Social\ChannelPayload;
use App\Support\Social\PostFormat;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 12.5b — §4.3's `social_draft`: eight posts written so that one exists.
 *
 * The claim this file defends is §1's, not §4.3's: on this channel the engine is
 * doing **selection rather than generation**, and the two are told apart by what
 * the pipeline is willing to produce nothing. So the loudest tests here are the
 * ones where a run completes having published nothing at all —
 * {@see every_candidate_failing_leaves_an_empty_slot_with_a_reason()} and
 * {@see a_throttled_week_refuses_the_candidate_an_ordinary_week_accepts()} —
 * because a content engine's default instinct is to fill the calendar, and §4.3
 * says that instinct has to be switched off explicitly, in code.
 *
 * The date is 2026-08-24, a Monday, the same one {@see SocialPlanTest} uses, and
 * the project runs in Lisbon rather than in UTC for the same reason: every rule
 * in §4.3 is about a person being awake, and a suite that agreed with the code
 * only at zero offset would pass for a project that posts at 03:00.
 *
 * The model gateway answers by role rather than by position. Three branches run
 * in parallel after `rank` and two of them can call a model, so a queue of
 * answers would be resolved by the graph's tie-break rather than by the test.
 */
final class SocialDraftTest extends TestCase
{
    use RefreshDatabase;

    private const string TIMEZONE = 'Europe/Lisbon';

    /**
     * A post that does everything §2 asks: one thought, a real question, a
     * figure, and a subject this project is actually about.
     *
     * 50 base + 18 for the question + 14 for the figure + 16 for being short
     * enough to read as a caption = 98, comfortably over both the ordinary bar
     * of 50 and the throttled one of 64. All three of §2's working shapes at
     * once, which is what a post doing everything §2 asks looks like.
     */
    private const string GOOD_POST = 'Hard water took about 6 months to fur up our kettle here. '
        .'What do you actually use on limescale?';

    private Project $project;

    private FakeModelGateway $models;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 06:00:00');

        config()->set('queue.default', 'sync');

        $this->project = Project::factory()->create([
            'timezone' => self::TIMEZONE,
            'original_data' => [],
            'is_ymyl' => false,
        ]);

        app(CurrentProject::class)->set($this->project);

        BrandBrief::factory()->create([
            'positioning' => 'We clean windows and kettles in Lisbon.',
            'tone' => 'Plain, specific, no exclamation marks.',
            'forbidden_topics' => ['discounts'],
            'competitors' => [],
        ]);

        // The corpus the project's vocabulary is read off. §4.3's native-post
        // gate is "сущности обязаны быть в пространстве Brief или корпуса", and
        // without a corpus there is no space to resolve into.
        ContentItem::factory()->create([
            'title' => 'How to descale a kettle',
            'entities' => ['limescale', 'hard water', 'kettle'],
            'cluster' => 'descaling',
            'state' => ContentItemState::Published,
            'published_at' => Carbon::now()->subWeek(),
        ]);

        /** @var FakeModelGateway $models */
        $models = app(ModelGateway::class);
        $this->models = $models;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------ the pool and its cost

    #[Test]
    public function five_to_ten_candidates_are_written_and_exactly_one_survives(): void
    {
        // §4.3: "генерирует 5–10 кандидатов и оставляет один".
        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $run = $this->draft($slot)->refresh();

        $this->assertSame(PipelineRunStatus::Completed, $run->status);

        $pool = $this->payload($run, DraftCandidates::key());

        $this->assertGreaterThanOrEqual(5, $pool['calls']);
        $this->assertLessThanOrEqual(10, $pool['calls']);
        $this->assertCount($pool['calls'], $pool['candidates']);

        // One call per candidate, and every one of them paid for.
        $this->assertSame($pool['calls'], $this->models->callCount());

        // And exactly one of them exists afterwards.
        $chosen = $this->payload($run, RankCandidates::key());

        $this->assertNotNull($chosen['candidate']);
        $this->assertSame(98, $chosen['score']);

        $slot->refresh();

        $this->assertSame(ContentItemState::Draft, $slot->state);
        $this->assertCount(1, ChannelPayload::fromArray((array) $slot->channel_payload)->segments);
    }

    #[Test]
    public function the_run_costs_every_candidate_and_not_only_the_published_one(): void
    {
        // §8: "Единица — опубликованный пост, а не сгенерированный: при отборе
        // 1 из 8 себестоимость поста в восемь раз больше стоимости одной
        // генерации, и отчёт, считающий вызовы, соврёт в разы."
        $this->answerWith(self::GOOD_POST);

        $run = $this->draft($this->slot())->refresh();

        $pool = $this->step($run, DraftCandidates::key());
        $calls = $this->payload($run, DraftCandidates::key())['calls'];

        // The fake gateway reports output tokens as the length of what it said,
        // so eight identical answers is eight times one answer — the arithmetic
        // §8 says a report counting calls gets wrong by a factor of eight.
        $this->assertSame($calls * mb_strlen(self::GOOD_POST), $pool->output_tokens);
        $this->assertGreaterThan(0, $pool->cost_micros);

        // And the whole of it lands on the run, which is what makes the cost of
        // a published post recoverable (§12.6).
        $this->assertSame((int) $run->steps()->sum('cost_micros'), $run->cost_micros);
        $this->assertGreaterThanOrEqual($pool->cost_micros, $run->cost_micros);
    }

    // ------------------------------------------------- the week's higher bar

    #[Test]
    public function a_throttled_week_refuses_the_candidate_an_ordinary_week_accepts(): void
    {
        // The half of §4.3 that is easiest to forget: "trailing reply rate ниже
        // порога → срезает частоту **и поднимает планку отбора**". A weak week
        // is not a shorter version of a good one, it publishes different things.
        //
        // The marginal post is the one §2 says nothing about: no question, no
        // figure, and too long to read as a caption under a picture. It scores
        // exactly the base, which is exactly the ordinary bar — and that is
        // what the throttled bar is raised past, because §4.3's weak week
        // refuses the merely inoffensive rather than refusing everything.
        //
        // It used to be a plain question, on the reading that a question was
        // marginal. It is not: §2 names it first among the shapes that work,
        // and a bar that refused it refused all three of them. See
        // {@see \Tests\Unit\Social\PostFormatTest}.
        // Still about this project's entities, because the guard's own gate is
        // upstream of the bar and a post that resolves into nothing would be
        // refused for a reason this test is not about.
        $marginal = 'Descaling a kettle is one of those jobs that looks finished long before it is, and '
            .'the limescale only shows itself again once the water has boiled a couple of times. We '
            .'think about hard water more than we probably should, and it still catches us out here.';

        $this->assertSame(PostFormat::BASE, PostFormat::score([$marginal]));

        $this->answerWith($marginal);

        $untroubled = $this->slot();
        $this->draft($untroubled);

        $this->assertSame(ContentItemState::Draft, $untroubled->refresh()->state);

        // The same project, the same pool, the same post — a different history.
        $this->trailingReplyRate(replies: 2, impressions: 1_000);

        $weak = $this->slot('A second thing to say');
        $run = $this->draft($weak);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        // Not drafted, not published, not lowered to fit.
        $this->assertSame(ContentItemState::Idea, $weak->refresh()->state);
        $this->assertNull($weak->channel_payload);

        $chosen = $this->payload($run, RankCandidates::key());

        $this->assertNull($chosen['candidate']);
        $this->assertSame(64, $chosen['bar']);
        $this->assertTrue($chosen['throttled']);
        $this->assertStringContainsString('selection bar of 64', (string) $chosen['refusal']);
    }

    // -------------------------------------------- the guard calls no model

    #[Test]
    public function the_guard_calls_no_model_at_all(): void
    {
        // §4.3: "guard_policy — детерминированный, без вызова модели". Asserted
        // twice, because the two failures are different: the run's own record
        // says the step spent nothing, and running the step by hand over the
        // same gateway shows it asks for nothing.
        $this->answerWith(self::GOOD_POST);

        $run = $this->draft($this->slot())->refresh();

        $guard = $this->step($run, GuardPolicy::key());

        $this->assertSame(PipelineStepStatus::Succeeded, $guard->status);
        $this->assertSame(0, $guard->cost_micros);
        $this->assertNull($guard->role);
        $this->assertNull($guard->model);

        // The same step, run again against the gateway the suite watches.
        $before = $this->models->callCount();

        $context = new StepContext(
            run: $run,
            project: $this->project,
            input: $run->input,
            dependencyOutputs: [
                LoadContext::key() => $this->payload($run, LoadContext::key()),
                DraftCandidates::key() => $this->payload($run, DraftCandidates::key()),
                RankCandidates::key() => $this->payload($run, RankCandidates::key()),
            ],
            runContext: [],
            gateway: $this->models,
        );

        app(GuardPolicy::class)->handle($context);

        $this->assertSame($before, $this->models->callCount());
        $this->assertSame([], $context->usage());
    }

    // ------------------------------------------------ every guard rule bites

    #[Test]
    public function a_segment_over_the_platform_limit_is_refused(): void
    {
        // A question with a figure, so it clears the bar and reaches the guard —
        // and 600 characters, which Threads will not accept (§2).
        $this->answerWith('Limescale costs us 6 hours a week. '.str_repeat('The kettle furs up again. ', 24).'What do you do?');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::LENGTH, 'Threads refuses anything over 500');
    }

    #[Test]
    public function a_chain_longer_than_the_limit_is_refused(): void
    {
        $this->answerWith(json_encode([
            'segments' => [
                'Hard water took 6 months to fur up the kettle.',
                'We tried vinegar first.',
                'Then citric acid.',
                'What do you use on limescale?',
            ],
            'chain_reason' => 'Four steps in order.',
        ]) ?: '');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::SEGMENT_COUNT, 'a chain may be at most 3');
    }

    #[Test]
    public function a_forbidden_topic_from_the_brief_is_refused(): void
    {
        // The brief in setUp() says this project never writes about discounts.
        $this->answerWith('Our discounts take 20% off limescale removal. Worth it?');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::FORBIDDEN_TOPIC, 'discounts');
    }

    #[Test]
    public function a_post_that_resolves_to_none_of_the_projects_entities_is_refused(): void
    {
        // §4.3: "для нативного поста — сущности обязаны быть в пространстве
        // Brief или корпуса". Nothing in this one says it is ours.
        $this->answerWith('We watched 3 seagulls argue on the pier this morning. Ever seen that?');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::UNRESOLVED_ENTITY, 'none of this project\'s entities');
    }

    #[Test]
    public function a_bare_link_is_refused(): void
    {
        // §2 names the bare link as one of the shapes that does not work. It is
        // ranked down as well as refused, so this one is built to clear the bar
        // — otherwise the guard's rule could never fire and would rot.
        $bare = 'https://example.com/water-rates — 40% more?';

        $this->assertGreaterThanOrEqual(50, PostFormat::score([$bare]));

        $this->answerWith($bare);

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::BARE_LINK, 'bare link');
    }

    #[Test]
    public function a_derivative_that_kept_too_little_of_its_parent_is_refused(): void
    {
        // §4.3 points at the rule that already exists rather than inventing a
        // second one, so the threshold is read off the class the repurpose tree
        // uses. This post keeps none of the three entities the article was about.
        $this->answerWith('We watched 3 seagulls argue on the pier this morning. Ever seen that?');

        $parent = ContentItem::factory()->create([
            'title' => 'Everything about descaling',
            'entities' => ['limescale', 'hard water', 'kettle'],
            'body_markdown' => 'A long article about limescale in hard water and what it does to a kettle.',
            'state' => ContentItemState::Published,
            'published_at' => Carbon::now()->subWeek(),
        ]);

        $slot = $this->slot('Three things we learned descaling kettles', SocialBand::Derivative, $parent);
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::DERIVATIVE_OVERLAP, 'under the 34%');

        $this->assertSame(
            DerivativeOverlap::percent(DerivativeOverlap::MINIMUM),
            34,
            'the guard quotes the existing rule rather than a threshold of its own',
        );
    }

    // -------------------------------------------------- the empty slot (§7)

    #[Test]
    public function every_candidate_failing_leaves_an_empty_slot_with_a_reason(): void
    {
        // Not a retry, not a lowered bar, not the least-bad of them. §4.3 makes
        // the empty slot a valid outcome and §7 makes the explanation
        // mandatory: "Молчащий автомат неотличим от сломанного."
        $this->answerWith('Limescale costs us 6 hours a week. '.str_repeat('The kettle furs up again. ', 24).'What do you do?');

        $slot = $this->slot();
        $run = $this->draft($slot);

        // The run is green. An empty slot is a decision, not a breakage.
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        // Nothing reached draft, and nothing was written onto the unit.
        $this->assertSame(ContentItemState::Idea, $slot->refresh()->state);
        $this->assertNull($slot->channel_payload);
        $this->assertNull($slot->body_markdown);
        $this->assertSame(0, ContentItem::query()->social()->inState(ContentItemState::Draft)->count());

        // And the reason is retrievable where §7's summary already reads the
        // week's refusals — one query, not one per pipeline.
        $plan = SocialPlan::forWeek($this->project, $slot->slot_at);

        $this->assertNotNull($plan);
        $this->assertNotEmpty(array_filter(
            $plan->reasons,
            static fn (array $reason): bool => $reason['code'] === SaveDraft::EMPTY_SLOT,
        ));

        $this->assertNotEmpty(array_filter(
            $plan->reasons,
            static fn (array $reason): bool => str_contains($reason['detail'], 'Threads refuses anything over'),
        ));

        // The run says so too, so "why did this run produce nothing" needs no
        // join to answer.
        $this->assertStringContainsString(
            'Threads refuses anything over',
            (string) ($run->context['social_draft.empty_reason'] ?? ''),
        );
    }

    #[Test]
    public function an_empty_slot_is_recorded_on_the_week_the_planner_already_wrote(): void
    {
        // Not a second row. §7's summary is one screen per week, and a slot that
        // came to nothing belongs beside the slots that were never placed.
        $this->answerWith('Nothing here says what this project is about at all.');

        $slot = $this->slot();

        $before = SocialPlan::record(
            $this->project,
            app(Governor::class)->verdict($this->project, $slot->slot_at),
            1,
        );
        $before->note('empty_slot', 'Season: nothing peaking in the next six weeks.');

        $this->draft($slot);

        $after = SocialPlan::forWeek($this->project, $slot->slot_at);

        $this->assertSame(1, SocialPlan::query()->count());
        $this->assertNotNull($after);
        $this->assertCount(2, $after->reasons);
    }

    // ------------------------------------------------------------ §10, YMYL

    #[Test]
    public function a_ymyl_post_is_factchecked(): void
    {
        // §10: "Для cryptoroute фактчек в social_draft — обязательный шаг, как в
        // генерации. Неверное число в посте расходится быстрее, чем в статье."
        $this->project->forceFill(['is_ymyl' => true])->save();
        $this->refreshTenant();

        $this->answerWith(self::GOOD_POST);
        $this->models->willAnswerRole('factcheck', 'PASS');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $check = $this->step($run, FactCheckPost::key());

        $this->assertSame(PipelineStepStatus::Succeeded, $check->status);
        $this->assertSame('factcheck', $check->role);

        // The verdict is on the unit, the same way an article carries its own.
        $slot->refresh();

        $this->assertTrue($slot->factcheck['passed'] ?? false);
        $this->assertTrue($slot->factcheck['required'] ?? false);
        $this->assertSame(ContentItemState::Draft, $slot->state);
    }

    #[Test]
    public function a_project_that_is_not_ymyl_is_not_factchecked(): void
    {
        // A check nobody needs is money on the critical path.
        $this->answerWith(self::GOOD_POST);

        $run = $this->draft($this->slot());

        $this->assertSame(PipelineStepStatus::Skipped, $this->step($run, FactCheckPost::key())->status);
    }

    #[Test]
    public function a_ymyl_post_that_fails_the_factcheck_leaves_the_slot_empty(): void
    {
        $this->project->forceFill(['is_ymyl' => true])->save();
        $this->refreshTenant();

        $this->answerWith(self::GOOD_POST);
        $this->models->willAnswerRole('factcheck', 'Six months is not a figure this business has given us.');

        $slot = $this->slot();
        $run = $this->draft($slot);

        // Green run, empty slot, stated reason — the same shape as every other
        // refusal, because §10 is a rule and not a malfunction.
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(ContentItemState::Idea, $slot->refresh()->state);
        $this->assertStringContainsString(
            'this project is YMYL',
            (string) ($run->context['social_draft.empty_reason'] ?? ''),
        );
    }

    // ------------------------------------------------------ one post, one thought

    #[Test]
    public function the_default_is_one_segment(): void
    {
        // §2: "Дефолт — один пост, одна мысль, с картинкой."
        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $run = $this->draft($slot)->refresh();

        $payload = ChannelPayload::fromArray((array) $slot->refresh()->channel_payload);

        $this->assertCount(1, $payload->segments);
        $this->assertSame(1, $run->context['social_draft.segments'] ?? null);
        $this->assertArrayNotHasKey('social_draft.chain_reason', $run->context);
    }

    #[Test]
    public function a_chain_with_no_justification_is_refused(): void
    {
        $this->answerWith(json_encode([
            'segments' => [
                'Hard water took 6 months to fur up our kettle.',
                'What do you use on limescale?',
            ],
        ]) ?: '');

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertRefused($run, $slot, GuardFinding::UNJUSTIFIED_CHAIN, 'one post, one thought');
    }

    #[Test]
    public function a_chain_with_a_recorded_justification_is_allowed(): void
    {
        $this->answerWith(json_encode([
            'segments' => [
                'Hard water took 6 months to fur up our kettle.',
                'What do you use on limescale?',
            ],
            'chain_reason' => 'The measurement and the question are two thoughts and the first sets up the second.',
        ]) ?: '');

        $slot = $this->slot();
        $run = $this->draft($slot)->refresh();

        $payload = ChannelPayload::fromArray((array) $slot->refresh()->channel_payload);

        $this->assertCount(2, $payload->segments);

        // "Обязан обосновать" only means anything if the reason survives the
        // run that accepted it.
        $this->assertStringContainsString(
            'two thoughts',
            (string) ($run->context['social_draft.chain_reason'] ?? ''),
        );
    }

    // ------------------------------------------------------------ §3's payload

    #[Test]
    public function the_saved_payload_is_the_canonical_shape(): void
    {
        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $this->draft($slot);

        $stored = (array) $slot->refresh()->channel_payload;

        // Round-trips through the class the Threads publisher reads, which is
        // the only definition of "canonical" that means anything (§3, §9).
        //
        // Key by key rather than array to array: the column is `jsonb` and
        // Postgres decides the order of its keys, so an identity assertion here
        // would be testing the storage engine's sort rather than the shape.
        $payload = ChannelPayload::fromArray($stored);

        foreach ($payload->toArray() as $key => $value) {
            $this->assertArrayHasKey($key, $stored);
            $this->assertEquals($value, $stored[$key]);
        }

        // And back again unchanged, which is what a resume depends on: §9's
        // journal is written into this same column after every publish call.
        $this->assertSame($payload->toArray(), ChannelPayload::fromArray($payload->toArray())->toArray());
        $this->assertSame(self::GOOD_POST, $payload->segments[0]->text);
        $this->assertSame('everyone', $payload->replyControl);
        $this->assertNotNull($payload->angle);

        // Nothing is live, and the journal says so. This pipeline drafts.
        $this->assertFalse($payload->hasPublishedRoot());
        $this->assertCount(1, $payload->unpublishedSegments());
        $this->assertNull($payload->permalink);
    }

    // ------------------------------------------------------------- the image

    #[Test]
    public function the_image_is_attached_as_an_asset_and_named_in_the_payload(): void
    {
        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $this->draft($slot);

        $asset = $slot->refresh()->assets()->where('role', AssetRole::Hero)->first();

        $this->assertNotNull($asset);

        $payload = ChannelPayload::fromArray((array) $slot->channel_payload);

        $this->assertSame((string) $asset->getKey(), $payload->segments[0]->assetId);
    }

    #[Test]
    public function an_unconfigured_image_provider_skips_rather_than_failing(): void
    {
        // The same decision the article pipeline made: a project with no image
        // key should still get its writing.
        $this->app->bind(ImageGenerationProvider::class, fn (): ImageGenerationProvider => new class extends FakeImageGeneration
        {
            public function isConfigured(): bool
            {
                return false;
            }
        });

        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $run = $this->draft($slot);

        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);
        $this->assertSame(PipelineStepStatus::Skipped, $this->step($run, GenerateImage::key())->status);

        $slot->refresh();

        $this->assertSame(ContentItemState::Draft, $slot->state);
        $this->assertSame(0, $slot->assets()->count());

        $payload = ChannelPayload::fromArray((array) $slot->channel_payload);

        $this->assertNull($payload->segments[0]->assetId);
    }

    // ------------------------------------------------------- it never publishes

    #[Test]
    public function the_run_leaves_the_unit_in_draft_and_never_beyond_it(): void
    {
        $this->answerWith(self::GOOD_POST);

        $slot = $this->slot();
        $this->draft($slot);

        $this->assertSame(ContentItemState::Draft, $slot->refresh()->state);
        $this->assertNull($slot->published_at);
        $this->assertNull($slot->public_url);
    }

    // -------------------------------------------------- what starts the run

    #[Test]
    public function the_command_drafts_a_slot_that_is_coming_due(): void
    {
        $this->answerWith(self::GOOD_POST);

        // §11.3's point, and the gap this closes: the planner writes a week of
        // slots and stops. Until something starts `social_draft`, the calendar
        // fills with times to publish at and nothing to publish.
        $slot = $this->slot();
        $slot->forceFill(['slot_at' => Carbon::now()->addHour()])->save();

        $this->console('social:draft');

        $this->assertSame(ContentItemState::Draft, $slot->refresh()->state);
    }

    #[Test]
    public function a_slot_further_out_than_the_lead_time_is_left_alone(): void
    {
        $this->answerWith(self::GOOD_POST);

        // Hours, not days. An article is drafted a day ahead so there is an
        // evening to read it in; a post written a day early is a post written
        // before the conversation it was going to join.
        $slot = $this->slot();
        $slot->forceFill(['slot_at' => Carbon::now()->addDay()])->save();

        $this->console('social:draft');

        $this->assertSame(ContentItemState::Idea, $slot->refresh()->state);
    }

    #[Test]
    public function an_expired_slot_is_not_drafted_for_the_reaper_to_kill(): void
    {
        $this->answerWith(self::GOOD_POST);

        // §5 kills a reactive draft that missed its window. Writing it first —
        // eight model calls — and then killing it obeys the rule the expensive
        // way round.
        $slot = $this->slot(band: SocialBand::Reaction);
        $slot->forceFill([
            'slot_at' => Carbon::now()->addHour(),
            'expires_at' => Carbon::now()->subMinute(),
        ])->save();

        $this->console('social:draft');

        $this->assertSame(ContentItemState::Idea, $slot->refresh()->state);
        $this->assertSame(0, $this->models->callCount());
    }

    #[Test]
    public function drafting_is_on_the_schedule_hourly_and_on_its_own(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'social:draft'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()?->expression);
    }

    // ----------------------------------------------------------------- setup

    /**
     * `artisan()` is declared as returning `PendingCommand|int`, and
     * `assertSuccessful()` only records the expectation — the command runs in
     * `__destruct()`, so it is run explicitly here. Same helper as
     * {@see SocialListenTest}.
     */
    private function console(string $command): void
    {
        /** @var PendingCommand $pending */
        $pending = $this->artisan($command);

        $pending->assertSuccessful()->run();
    }

    /** Every candidate call answers with the same post. */
    private function answerWith(string $post): void
    {
        $this->models->willAnswerRole('social_candidates', $post);
    }

    /**
     * A slot as `social_plan` leaves one: a time, a band, a title, no body.
     */
    private function slot(
        string $title = 'What people actually use on limescale',
        SocialBand $band = SocialBand::Question,
        ?ContentItem $parent = null,
    ): ContentItem {
        return ContentItem::factory()->create([
            'parent_id' => $parent?->getKey(),
            'type' => ContentItemType::SocialPost,
            'social_band' => $band,
            'title' => $title,
            'target_query' => $title,
            'entities' => [],
            'state' => ContentItemState::Idea,
            'slot_at' => Carbon::parse('2026-08-25 09:00:00', self::TIMEZONE),
            'scheduled_for' => '2026-08-25',
        ]);
    }

    private function draft(ContentItem $slot): PipelineRun
    {
        $id = (string) $slot->getKey();

        return app(PipelineRunner::class)->start(
            'social_draft',
            $this->project,
            ['content_item_id' => $id],
            $id,
        );
    }

    /** A fortnight of measured days at one rate, which is what throttles. */
    private function trailingReplyRate(int $replies, int $impressions): void
    {
        ProjectState::factory()->count(14)->create([
            'post_impressions' => $impressions,
            'post_replies' => $replies,
        ]);
    }

    /**
     * The whole refusal, asserted from both ends: the unit is untouched, and the
     * rule that refused it is named in words §7 can show somebody.
     */
    private function assertRefused(
        PipelineRun $run,
        ContentItem $slot,
        string $code,
        string $needle,
    ): void {
        $this->assertSame(PipelineRunStatus::Completed, $run->refresh()->status);

        $slot->refresh();

        $this->assertSame(ContentItemState::Idea, $slot->state);
        $this->assertNull($slot->channel_payload);

        $guard = $this->payload($run, GuardPolicy::key());
        $codes = array_map(
            static fn (array $finding): string => $finding['code'],
            $guard['findings'],
        );

        $this->assertContains($code, $codes);
        $this->assertStringContainsString($needle, $guard['findings'][array_search($code, $codes, true)]['detail']);
    }

    private function refreshTenant(): void
    {
        $this->project->refresh();

        app(CurrentProject::class)->set($this->project);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PipelineRun $run, string $stepKey): array
    {
        $output = $this->step($run, $stepKey)->output;

        return is_array($output) ? $output : [];
    }

    private function step(PipelineRun $run, string $stepKey): PipelineStep
    {
        return $run->steps()->where('step_key', $stepKey)->firstOrFail();
    }
}
