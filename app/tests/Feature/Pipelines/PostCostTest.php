<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\User;
use App\Support\Metering\PostCostReport;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §8, and §12's sixth exit criterion.
 *
 * > Единица — опубликованный пост, а не сгенерированный: при отборе 1 из 8
 * > себестоимость поста в восемь раз больше стоимости одной генерации, и отчёт,
 * > считающий вызовы, соврёт в разы.
 *
 * The central test here is {@see the_cost_of_a_published_post_includes_the_candidates_that_lost()}.
 * Everything else in this file is a way of checking that the number it asserts
 * stays honest when the window contains other kinds of spending.
 */
final class PostCostTest extends TestCase
{
    use RefreshDatabase;

    private const string NOW = '2026-08-12 10:00:00';

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));

        $this->project = Project::factory()->create(['slug' => 'post-cost']);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    // ------------------------------------------------------- the whole claim

    #[Test]
    public function the_cost_of_a_published_post_includes_the_candidates_that_lost(): void
    {
        $post = $this->socialPost(published: true);

        // Eight written, one kept: §4.3's pool, priced as one step.
        $this->draftRun($post, candidates: 800_000, calls: 8, image: 180_000, rest: 20_000);

        $report = $this->report();

        $this->assertSame(1, $report->post['published']);
        $this->assertSame(8, $report->post['candidates']);

        // One generation is what a report counting model calls would have
        // printed as the price of a post.
        $this->assertSame(100_000, $report->post['per_generation_micros']);

        // The post costs the whole pool, plus its picture, plus the ranking
        // that threw seven of them away — not one generation.
        $this->assertSame(1_000_000, $report->post['average_micros']);
        $this->assertGreaterThanOrEqual(
            8 * (int) $report->post['per_generation_micros'],
            (int) $report->post['average_micros'],
            'A published post must carry the candidates that lost (§8).',
        );

        $this->assertSame(8.0, $report->post['candidates_per_post']);
    }

    #[Test]
    public function a_slot_that_came_to_nothing_is_paid_for_by_the_post_that_went_out(): void
    {
        // Two slots drafted at the same price. One published; the other was
        // left empty, which §4.3 calls a valid result — and §8 makes visible.
        $published = $this->socialPost(published: true);
        $empty = $this->socialPost(published: false);

        $this->draftRun($published, candidates: 400_000, calls: 5, image: 100_000, rest: 0);
        $this->draftRun($empty, candidates: 400_000, calls: 5, image: 100_000, rest: 0);

        $report = $this->report();

        $this->assertSame(1, $report->post['published']);
        // The whole week's drafting over the one post that went out. A
        // denominator of two would report the post at half its real price.
        $this->assertSame(1_000_000, $report->post['average_micros']);
        $this->assertSame(10, $report->post['candidates']);
        $this->assertSame(10.0, $report->post['candidates_per_post']);
    }

    #[Test]
    public function nothing_published_reports_no_price_rather_than_a_free_post(): void
    {
        $this->draftRun($this->socialPost(published: false), candidates: 400_000, calls: 5, image: 0, rest: 0);

        $report = $this->report();

        $this->assertSame(0, $report->post['published']);
        $this->assertNull($report->post['average_micros']);
        $this->assertNull($report->post['candidates_per_post']);
        // The money was still spent, and the report still says how much.
        $this->assertSame(400_000, $report->post['cost_micros']);
    }

    // ------------------------------------------------------------ four lines

    #[Test]
    public function listening_candidates_images_and_replies_are_separate_lines(): void
    {
        $post = $this->socialPost(published: true);
        $this->draftRun($post, candidates: 800_000, calls: 8, image: 180_000, rest: 20_000);

        $this->pipelineRun('social_listen', 24_000);
        $this->pipelineRun('social_listen', 24_000);
        $this->pipelineRun('social_engage', 60_000);

        $lines = collect($this->report()->lines)->keyBy('key');

        $this->assertSame(
            ['listening', 'candidates', 'images', 'replies'],
            collect($this->report()->lines)->pluck('key')->all(),
        );

        $this->assertSame(48_000, $lines['listening']['cost_micros']);
        $this->assertSame(2, $lines['listening']['units']);

        $this->assertSame(800_000, $lines['candidates']['cost_micros']);
        $this->assertSame(8, $lines['candidates']['units']);

        $this->assertSame(180_000, $lines['images']['cost_micros']);
        $this->assertSame(1, $lines['images']['units']);

        $this->assertSame(60_000, $lines['replies']['cost_micros']);
        $this->assertSame(1, $lines['replies']['units']);
    }

    #[Test]
    public function listening_is_a_standing_cost_and_is_never_divided_by_posts(): void
    {
        $this->socialPost(published: true);
        $this->pipelineRun('social_listen', 30_000);

        $lines = collect($this->report()->lines)->keyBy('key');

        // Cheap, hourly and constant (§8). Divided by the window rather than by
        // the posts: publish nothing for a fortnight and a per-post reading of
        // a fixed sweep runs away while the bill does not move.
        $this->assertTrue($lines['listening']['standing']);
        $this->assertNull($lines['listening']['per_post_micros']);
        $this->assertSame(1_000, $lines['listening']['per_day_micros']);
    }

    #[Test]
    public function listening_and_replying_stay_out_of_what_a_post_costs(): void
    {
        $post = $this->socialPost(published: true);
        $this->draftRun($post, candidates: 500_000, calls: 5, image: 0, rest: 0);

        $this->pipelineRun('social_listen', 400_000);
        $this->pipelineRun('social_engage', 300_000);

        // Neither is per post: one is hourly whatever happens, and the other is
        // §1's other half of the channel.
        $this->assertSame(500_000, $this->report()->post['average_micros']);
    }

    // ---------------------------------------------------- post versus article

    #[Test]
    public function the_cost_of_a_post_is_separable_from_the_cost_of_an_article(): void
    {
        $post = $this->socialPost(published: true);
        $this->draftRun($post, candidates: 800_000, calls: 8, image: 200_000, rest: 0);

        $article = ContentItem::factory()->published()->create();
        $this->pipelineRun('generation', 3_000_000, $article);

        $report = $this->report();

        // §12's sixth exit criterion, as two numbers that do not contain each
        // other.
        $this->assertSame(1_000_000, $report->post['average_micros']);
        $this->assertSame(3_000_000, $report->article['average_micros']);
        $this->assertSame(1, $report->post['published']);
        $this->assertSame(1, $report->article['published']);
    }

    #[Test]
    public function a_repurposed_derivative_is_costed_as_a_post_and_not_as_its_parent(): void
    {
        $parent = ContentItem::factory()->published()->create();
        $derivative = ContentItem::factory()
            ->derivedFrom($parent, ContentItemType::SocialPost)
            ->published()
            ->create();

        $this->pipelineRun('repurpose', 120_000, $derivative);

        $report = $this->report();

        // The unit is what the run was about, not the pipeline that ran it. §5
        // makes the derivative one supplier of six, and it is still a post.
        $this->assertSame(120_000, $report->post['cost_micros']);
        $this->assertSame(0, $report->article['cost_micros']);
    }

    #[Test]
    public function the_report_counts_only_the_project_it_was_asked_about(): void
    {
        $this->draftRun($this->socialPost(published: true), candidates: 100_000, calls: 4, image: 0, rest: 0);

        $other = Project::factory()->create(['slug' => 'somebody-else']);
        app(CurrentProject::class)->run($other, function () use ($other): void {
            $post = ContentItem::factory()->published()->create([
                'type' => ContentItemType::SocialPost,
                'project_id' => $other->getKey(),
            ]);

            PipelineRun::factory()->create([
                'project_id' => $other->getKey(),
                'pipeline' => 'social_draft',
                'content_item_id' => $post->getKey(),
                'cost_micros' => 9_000_000,
            ]);
        });

        app(CurrentProject::class)->set($this->project);

        $this->assertSame(100_000, $this->report()->post['average_micros']);
    }

    // --------------------------------------------------------- the surfaces

    #[Test]
    public function the_published_post_is_on_the_metering_screen(): void
    {
        $post = $this->socialPost(published: true);
        $this->draftRun($post, candidates: 800_000, calls: 8, image: 200_000, rest: 0);
        $this->pipelineRun('social_listen', 20_000);
        $this->pipelineRun('social_engage', 40_000);

        $article = ContentItem::factory()->published()->create();
        $this->pipelineRun('generation', 2_500_000, $article);

        $this->actingAs($this->owner)
            ->get(route('metering.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('metering/index')
                ->where('social.post.published', 1)
                ->where('social.post.average_micros', 1_000_000)
                ->where('social.post.per_generation_micros', 100_000)
                ->where('social.article.average_micros', 2_500_000)
                // §8's four lines, in §8's order.
                ->where('social.lines.0.key', 'listening')
                ->where('social.lines.1.key', 'candidates')
                ->where('social.lines.2.key', 'images')
                ->where('social.lines.3.key', 'replies')
                ->etc()
            );
    }

    #[Test]
    public function the_cost_command_reports_the_published_post_beside_the_article(): void
    {
        $post = $this->socialPost(published: true);
        $this->draftRun($post, candidates: 800_000, calls: 8, image: 200_000, rest: 0);

        $this->cost('post-cost')
            ->assertSuccessful()
            ->expectsOutputToContain('the unit is the published post')
            ->expectsOutputToContain('Per published post')
            ->expectsOutputToContain('Per published article')
            ->expectsOutputToContain('Listening')
            ->expectsOutputToContain('Candidates')
            ->expectsOutputToContain('Images')
            ->expectsOutputToContain('Replies');
    }

    // ---------------------------------------------------------------- setup

    private function report(): PostCostReport
    {
        return PostCostReport::for($this->project, CarbonImmutable::parse(self::NOW)->subDays(30));
    }

    private function socialPost(bool $published): ContentItem
    {
        $factory = ContentItem::factory();

        return ($published ? $factory->published() : $factory)->create([
            'type' => ContentItemType::SocialPost,
            'state' => $published ? ContentItemState::Published : ContentItemState::Idea,
            'published_at' => $published ? now() : null,
        ]);
    }

    /**
     * One run of `social_draft` over a slot, priced step by step.
     *
     * The header total is set to the sum of the steps, which is what
     * {@see PipelineRun::rollUpTotals()} would have written — the report reads
     * the header for spend and the steps for §8's lines, and a factory row
     * where the two disagree would test nothing.
     */
    private function draftRun(ContentItem $unit, int $candidates, int $calls, int $image, int $rest): PipelineRun
    {
        $run = $this->pipelineRun('social_draft', $candidates + $image + $rest, $unit);

        PipelineStep::factory()->for($run, 'pipelineRun')->create([
            'step_key' => PostCostReport::CANDIDATES_STEP,
            'position' => 1,
            'cost_micros' => $candidates,
            // The shape CandidatePoolPayload stores: the whole pool, and how
            // many model calls made it.
            'output' => ['calls' => $calls, 'candidates' => []],
        ]);

        if ($image > 0) {
            PipelineStep::factory()->for($run, 'pipelineRun')->create([
                'step_key' => PostCostReport::IMAGE_STEP,
                'position' => 2,
                'cost_micros' => $image,
                'output' => ['path' => 'social/one.png'],
            ]);
        }

        if ($rest > 0) {
            PipelineStep::factory()->for($run, 'pipelineRun')->create([
                'step_key' => 'rank',
                'position' => 3,
                'cost_micros' => $rest,
                'output' => [],
            ]);
        }

        return $run;
    }

    private function pipelineRun(string $pipeline, int $costMicros, ?ContentItem $unit = null): PipelineRun
    {
        return PipelineRun::factory()->create([
            'pipeline' => $pipeline,
            'content_item_id' => $unit?->getKey(),
            'cost_micros' => $costMicros,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function cost(string $project, array $options = []): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('pipeline:cost', ['project' => $project, ...$options]);

        return $command;
    }
}
