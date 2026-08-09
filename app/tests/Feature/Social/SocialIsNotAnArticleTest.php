<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Enums\SearchIntent;
use App\Enums\SocialBand;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Engine\EngineTickTest;
use Tests\TestCase;

/**
 * §3 made the parent optional for a social unit. This is what that had to mean
 * everywhere else.
 *
 * Phase 12.1 made a parentless post *representable*; the engine around it still
 * read "no parent" as "an article", because until then the two were the same
 * sentence. Every test here is one place that read it that way: the pull API
 * would have handed a static site a 300-character post as a page, the tick
 * would have written it with the article pipeline and approved it under a flag
 * that never heard of {@see SocialBand::canEverAutopublish()}, planning would
 * have counted it as a subject already covered, and the repurpose tree would
 * have cut articles up for the one channel that punishes exactly that.
 *
 * The planning one is the one to keep working if the others ever become
 * inconvenient. §1.3 says the reverse flow matters more than the forward one —
 * a question asked in public is the reason to write the article, not a reason
 * not to — and getting that backwards is invisible: nothing fails, a topic
 * simply never gets planned.
 */
final class SocialIsNotAnArticleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['weekly_target' => 2]);
        app(CurrentProject::class)->set($this->project);

        config()->set('queue.default', 'sync');
    }

    // ------------------------------------------------------------- the scope

    #[Test]
    public function a_published_post_with_no_parent_is_not_a_root(): void
    {
        $article = ContentItem::factory()->published()->create();
        $post = $this->socialPost();
        $derivative = ContentItem::factory()->derivedFrom($article)->create();

        // `roots()` is the engine's word for "an article", and it has to keep
        // meaning that now that a post can stand on its own (§3).
        $this->assertSame(
            [$article->getKey()],
            ContentItem::query()->roots()->pluck('id')->all(),
        );

        // Both kinds of post are on the other side of the line: the native one
        // and the one cut out of an article. The two scopes partition the
        // table, so nothing falls between them.
        $this->assertEqualsCanonicalizing(
            [$post->getKey(), $derivative->getKey()],
            ContentItem::query()->social()->pluck('id')->all(),
        );

        $this->assertSame(3, ContentItem::query()->count());
    }

    // ---------------------------------------------------------- the pull API

    #[Test]
    public function the_pull_api_does_not_serve_a_post_as_a_page(): void
    {
        $token = 'a-pull-token';

        Channel::factory()->create([
            'type' => ChannelType::PullApi,
            'name' => 'Static site',
            'secret' => $token,
        ]);

        ContentItem::factory()->published()->create([
            'slug' => 'how-often-to-clean-windows',
            'public_url' => 'https://site.test/how-often-to-clean-windows',
        ]);

        $this->socialPost(['slug' => 'threads-question']);

        $response = $this->withToken($token)->getJson('/api/content')->assertOk();

        // A static site rebuilds from this list. A post in it is a page on the
        // site with 300 characters on it, in the sitemap, with an article's
        // schema — the site's own quality signal, spent on something that was
        // never meant to be a page.
        $this->assertSame(['how-often-to-clean-windows'], $response->json('data.*.slug'));
    }

    // -------------------------------------------------------- the engine tick

    #[Test]
    public function the_tick_does_not_write_a_post_with_the_article_pipeline(): void
    {
        Queue::fake();

        $plan = ContentPlan::factory()->create();

        $this->socialPost([
            'state' => ContentItemState::Idea,
            'published_at' => null,
            'content_plan_id' => $plan->getKey(),
            'scheduled_for' => now()->addDay(),
        ]);

        $this->tick();

        // The generation pipeline researches a SERP, writes a two-thousand-word
        // outline and fact-checks it. Pointed at a post it is the wrong tool
        // and the wrong bill; §4.3 gives social units their own pipeline.
        $this->assertFalse(
            PipelineRun::acrossProjects()->where('pipeline', 'generation')->exists(),
            'A social idea was handed to the article generation pipeline.',
        );
    }

    #[Test]
    public function a_project_wide_autopublish_flag_cannot_release_a_reaction(): void
    {
        Queue::fake();

        $this->project->forceFill(['autopublish' => true])->save();

        // §5 marks the reactive band "автопаблиш: никогда" — a comment on news
        // is the one place where being wrong is both fast and public, which is
        // also why its drafts are killed rather than published late.
        $reaction = $this->socialPost([
            'state' => ContentItemState::Draft,
            'published_at' => null,
            'social_band' => SocialBand::Reaction,
            'body_markdown' => $this->publishableBody(),
        ]);

        $article = ContentItem::factory()->create([
            'state' => ContentItemState::Draft,
            'body_markdown' => $this->publishableBody(),
        ]);

        $this->tick();

        $this->assertSame(ContentItemState::Draft, $reaction->refresh()->state);

        // …and the flag still does what the operator asked it to do, so this is
        // a rule about what a post is rather than the auto-approval path being
        // broken.
        $this->assertSame(ContentItemState::Approved, $article->refresh()->state);
    }

    // ------------------------------------------------ §1.3, the reverse flow

    #[Test]
    public function a_question_asked_in_public_does_not_block_the_article_answering_it(): void
    {
        $this->idea('how often to clean windows');
        $this->idea('what window cleaning costs');

        // The post that makes the article worth writing: same subject, same
        // words, published.
        $this->socialPost([
            'target_query' => 'how often to clean windows',
            'title' => 'How often do you actually clean your windows?',
            'social_band' => SocialBand::Question,
        ]);

        app(PipelineRunner::class)->start('planning', $this->project, []);

        $planned = ContentPlan::query()->firstOrFail()->contentItems()->pluck('target_query')->all();

        // Both filters in `select_topics` had to stop counting posts for this
        // to hold: the exact-string one over published topics, and the vector
        // one over "existing work". Either alone would have dropped the idea
        // with a reason an operator would have read as correct.
        $this->assertContains('how often to clean windows', $planned);
        $this->assertContains('what window cleaning costs', $planned);
    }

    #[Test]
    public function a_published_article_still_blocks_its_own_topic(): void
    {
        $this->idea('how often to clean windows');

        // The same setup with an article instead of a post. Without this the
        // test above would pass just as well against a `roots()` that had
        // stopped filtering anything at all.
        ContentItem::factory()->published()->create([
            'target_query' => 'how often to clean windows',
            'locale' => $this->project->default_locale,
        ]);

        app(PipelineRunner::class)->start('planning', $this->project, []);

        $plan = ContentPlan::query()->first();
        $planned = $plan === null ? [] : $plan->contentItems()->pluck('target_query')->all();

        $this->assertNotContains('how often to clean windows', $planned);
    }

    // ------------------------------------------------ the derivative fan-out

    #[Test]
    public function threads_is_social_and_still_takes_no_cross_posts(): void
    {
        // The distinction the enum exists to hold: §1's third fact is that a
        // cross-post fails on tone, not that Threads is not a social channel.
        $this->assertTrue(ChannelType::Threads->isSocial());
        $this->assertFalse(ChannelType::Threads->takesArticleDerivatives());

        $this->assertTrue(ChannelType::LinkedIn->takesArticleDerivatives());
        $this->assertFalse(ChannelType::Webhook->takesArticleDerivatives());
    }

    #[Test]
    public function planning_does_not_promise_an_article_to_threads(): void
    {
        Channel::factory()->social(ChannelType::Threads)->create(['name' => 'Threads']);
        Channel::factory()->social(ChannelType::LinkedIn)->create(['name' => 'LinkedIn']);

        $this->idea('how often to clean windows');

        app(PipelineRunner::class)->start('planning', $this->project, []);

        $unit = ContentItem::query()->roots()->whereNotNull('content_plan_id')->firstOrFail();

        // A Threads post is planned by `social_plan` against the Derivative
        // band and its ≤1/week ceiling (§5), not by an article's calendar row.
        $this->assertSame(['linkedin'], $unit->planned_derivatives);
    }

    #[Test]
    public function the_repurpose_tree_does_not_deliver_to_threads(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Plain and practical.']);

        Channel::factory()->social(ChannelType::Threads)->create(['name' => 'Threads']);
        Channel::factory()->social(ChannelType::LinkedIn)->create(['name' => 'LinkedIn']);

        $this->models()->willAnswerRole(
            'draft',
            'Lisbon flats need their windows cleaned every six weeks. Atlantic salt is why.',
        );

        $article = ContentItem::factory()->published()->create([
            'title' => 'How often to clean windows in Lisbon',
            'slug' => 'how-often-to-clean-windows',
            'summary' => 'Lisbon flats need cleaning every six weeks.',
            'body_markdown' => '## Why',
            'entities' => ['Lisbon', 'Atlantic salt', 'six weeks'],
            // A plan written before Threads was ruled out. The step intersects
            // what was planned with what may take it, so a stale row cannot
            // reopen the door.
            'planned_derivatives' => ['linkedin', 'threads'],
            'public_url' => 'https://site.test/how-often-to-clean-windows',
        ]);

        app(PipelineRunner::class)->start('repurpose', $this->project, [], $article->getKey());

        $this->assertSame(['linkedin'], $article->derivatives()->pluck('channel_type')->all());
    }

    #[Test]
    public function a_project_with_only_threads_has_nowhere_to_repurpose_to(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Plain and practical.']);

        Channel::factory()->social(ChannelType::Threads)->create(['name' => 'Threads']);

        $article = ContentItem::factory()->published()->create([
            'body_markdown' => '## Why',
            'planned_derivatives' => [],
        ]);

        $run = app(PipelineRunner::class)->start('repurpose', $this->project, [], $article->getKey());

        $run = PipelineRun::acrossProjects()->whereKey($run->getKey())->firstOrFail();

        // Failing is the right answer, and a cheap one: it fails at the first
        // step, before a single token is spent writing a post that had no
        // business existing. The message is asserted because the step has two
        // other ways to fail and this test is only about one of them.
        $this->assertSame(PipelineRunStatus::Failed, $run->status);
        $this->assertSame('load_parent', $run->failed_step_key);
        $this->assertStringContainsString(
            'No social channel is connected',
            (string) ($run->error['message'] ?? ''),
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A native social unit: published, no parent, no article anywhere behind it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function socialPost(array $attributes = []): ContentItem
    {
        return ContentItem::factory()->published()->create([
            'type' => ContentItemType::SocialPost,
            'parent_id' => null,
            'social_band' => SocialBand::Question,
            'channel_type' => ChannelType::Threads->value,
            'title' => 'How often do you actually clean your windows?',
            'body_markdown' => 'How often do you actually clean your windows?',
            'public_url' => null,
            ...$attributes,
        ]);
    }

    private function idea(string $query): ContentItem
    {
        return ContentItem::factory()->create([
            'target_query' => $query,
            'cluster' => $query,
            'intent' => SearchIntent::Informational->value,
            'topic_volume' => 500,
            'topic_difficulty' => 20,
            'locale' => $this->project->default_locale,
        ]);
    }

    /**
     * As in {@see EngineTickTest}: `artisan()` returns a
     * PendingCommand that only runs on destruction, so it is run explicitly.
     */
    private function tick(): void
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('engine:tick');

        $command->assertSuccessful()->run();
    }

    /** A body that clears every check the style guide calls non-negotiable. */
    private function publishableBody(): string
    {
        return "## Where a weekly clean is the wrong call\n\n"
            .'A deep clean takes about three hours. Bathrooms take longest. We bring our own '
            .'cloths and sprays. If you have marble, say so first, because it needs a '
            .'pH-neutral product and most supermarket sprays will etch the surface beyond '
            ."repair.\n\n"
            ."## What a visit covers\n\n"
            ."Most flats need one visit a week. Ovens take 45 minutes on their own.\n";
    }

    private function models(): FakeModelGateway
    {
        /** @var FakeModelGateway $gateway */
        $gateway = app(ModelGateway::class);

        return $gateway;
    }
}
