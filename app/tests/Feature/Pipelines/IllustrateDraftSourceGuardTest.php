<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Ai\FakeModelGateway;
use App\Content\ArticleBusinessFacts;
use App\Enums\ContentItemState;
use App\Media\HeroImage;
use App\Media\MediaWriteFailed;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\IllustrateDraft;
use App\Support\Content\SafeMarkdown;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Expectation;
use Mockery\MockInterface;
use Tests\TestCase;

final class IllustrateDraftSourceGuardTest extends TestCase
{
    use RefreshDatabase;

    private ContentItem $item;

    private StepContext $context;

    private ArticleBusinessFacts $facts;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['media.inline.count' => 1]);
        $project = Project::factory()->create();
        app(CurrentProject::class)->set($project);
        $body = "## First section\n\nThe checked first paragraph.\n\n## Second section\n\nThe checked second paragraph.";
        $this->item = ContentItem::factory()->draft()->create(['body_markdown' => $body, 'body_html' => app(SafeMarkdown::class)->render($body)]);
        $run = PipelineRun::factory()->create(['pipeline' => 'generation', 'content_item_id' => $this->item->id]);
        $this->context = new StepContext($run, $project, [], [], [], new FakeModelGateway);
        $this->facts = app(ArticleBusinessFacts::class);
        $this->facts->compile($this->context, $this->item);
        $this->facts->seal($this->context, $this->item);
    }

    public function test_an_edit_before_illustration_is_not_resealed_or_sent_to_the_image_provider(): void
    {
        $originalSeal = $this->seal();
        $this->item->update(['summary' => 'A newer unchecked business claim.']);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->once()->andReturnTrue();
        $hero->shouldNotReceive('for');
        $this->app->instance(HeroImage::class, $hero);
        try {
            app(IllustrateDraft::class)->handle($this->context);
            $this->fail('An unchecked edit was illustrated.');
        } catch (TerminalStepFailure) {
            $this->assertSame($originalSeal, $this->seal());
            $this->assertSame([], $this->context->spent());
            $this->assertNotNull($this->facts->refusal($this->item->fresh()));
        }
    }

    public function test_an_edit_during_an_image_call_is_preserved_with_paid_cost_and_old_seal(): void
    {
        $originalSeal = $this->seal();
        $heroAsset = Asset::factory()->hero()->create(['content_item_id' => $this->item->id]);
        $inline = Asset::factory()->inline()->create(['content_item_id' => $this->item->id]);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->once()->andReturnTrue();
        $this->imageExpectation($hero, 'for')->once()->andReturn($this->image($heroAsset, 100));
        $this->imageExpectation($hero, 'inline')->once()->andReturnUsing(function () use ($inline): array {
            $this->item->fresh()->update(['body_markdown' => 'A newer owner-written paragraph.', 'body_html' => '<p>A newer owner-written paragraph.</p>']);

            return $this->image($inline, 200);
        });
        $this->app->instance(HeroImage::class, $hero);
        try {
            app(IllustrateDraft::class)->handle($this->context);
            $this->fail('The image callback overwrote the newer draft.');
        } catch (TerminalStepFailure) {
            $this->assertSame('A newer owner-written paragraph.', $this->item->fresh()->body_markdown);
            $this->assertSame($originalSeal, $this->seal());
            $this->assertSame(300, array_sum(array_column($this->context->spent(), 'cost_micros')));
            $this->assertDatabaseHas('assets', ['id' => $inline->id]);
            $this->assertNotNull($this->facts->refusal($this->item->fresh()));
        }
    }

    public function test_approval_during_image_generation_does_not_mutate_the_approved_article(): void
    {
        config(['media.inline.count' => 0]);
        $originalSeal = $this->seal();
        $asset = Asset::factory()->hero()->create(['content_item_id' => $this->item->id]);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->once()->andReturnTrue();
        $this->imageExpectation($hero, 'for')->once()->andReturnUsing(function () use ($asset): array {
            $this->item->fresh()->approve();

            return $this->image($asset, 100);
        });
        $this->app->instance(HeroImage::class, $hero);
        try {
            app(IllustrateDraft::class)->handle($this->context);
            $this->fail('An approved article was resealed by illustration.');
        } catch (TerminalStepFailure) {
            $this->assertSame(ContentItemState::Approved, $this->item->fresh()->state);
            $this->assertSame($originalSeal, $this->seal());
            $this->assertSame(100, array_sum(array_column($this->context->spent(), 'cost_micros')));
        }
    }

    public function test_only_image_decoration_updates_the_seal_of_an_unchanged_checked_draft(): void
    {
        $originalSeal = $this->seal();
        $heroAsset = Asset::factory()->hero()->create(['content_item_id' => $this->item->id]);
        $inline = Asset::factory()->inline()->create(['content_item_id' => $this->item->id]);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->once()->andReturnTrue();
        $this->imageExpectation($hero, 'for')->once()->andReturn($this->image($heroAsset, 100));
        $this->imageExpectation($hero, 'inline')->once()->andReturn($this->image($inline, 200));
        $this->app->instance(HeroImage::class, $hero);
        app(IllustrateDraft::class)->handle($this->context);
        $this->assertNotSame($originalSeal, $this->seal());
        $this->assertNull($this->facts->refusal($this->item->fresh()));
        $this->assertStringContainsString('The checked second paragraph.', $this->item->fresh()->body_markdown);
        $this->assertStringContainsString('![Second section]', $this->item->fresh()->body_markdown);
        $this->assertSame(300, array_sum(array_column($this->context->spent(), 'cost_micros')));
    }

    public function test_an_inline_storage_failure_retains_the_paid_image_spend(): void
    {
        $heroAsset = Asset::factory()->hero()->create(['content_item_id' => $this->item->id]);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->once()->andReturnTrue();
        $this->imageExpectation($hero, 'for')->once()->andReturn($this->image($heroAsset, 100));
        $this->imageExpectation($hero, 'inline')->once()->andThrow((new MediaWriteFailed('Synthetic disk failure'))->withSpend('fixture', 'image', 200));
        $this->app->instance(HeroImage::class, $hero);
        app(IllustrateDraft::class)->handle($this->context);
        $this->assertSame(300, array_sum(array_column($this->context->spent(), 'cost_micros')));
        $this->assertNull($this->facts->refusal($this->item->fresh()));
    }

    public function test_a_retry_after_saving_reuses_images_without_duplicating_the_body(): void
    {
        $body = str_replace('Second section', 'Cleaning under $100', $this->item->body_markdown);
        $this->item->update(['body_markdown' => $body, 'body_html' => app(SafeMarkdown::class)->render($body)]);
        $this->facts->seal($this->context, $this->item);
        $heroAsset = Asset::factory()->hero()->create(['content_item_id' => $this->item->id]);
        $inline = Asset::factory()->inline()->create(['content_item_id' => $this->item->id]);
        $hero = Mockery::mock(HeroImage::class);
        $this->imageExpectation($hero, 'isConfigured')->twice()->andReturnTrue();
        $this->imageExpectation($hero, 'for')->twice()->andReturn($this->image($heroAsset, 100), $this->image($heroAsset, 0));
        $this->imageExpectation($hero, 'inline')->twice()->andReturn($this->image($inline, 200), $this->image($inline, 0));
        $this->app->instance(HeroImage::class, $hero);
        app(IllustrateDraft::class)->handle($this->context);
        $body = $this->item->fresh()->body_markdown;
        $seal = $this->seal();
        app(IllustrateDraft::class)->handle($this->context);
        $this->assertSame($body, $this->item->fresh()->body_markdown);
        $this->assertSame($seal, $this->seal());
        $this->assertSame(1, substr_count($body, '![Cleaning under $100]'));
        $this->assertNull($this->facts->refusal($this->item->fresh()));
        $this->assertSame(300, array_sum(array_column($this->context->spent(), 'cost_micros')));
    }

    private function seal(): string
    {
        return DB::table('article_business_contexts')->where('pipeline_run_id', $this->context->run->id)->sole()->body_hash;
    }

    private function imageExpectation(MockInterface $mock, string $method): Expectation
    {
        $mock->shouldReceive($method);
        $expectation = $mock->mockery_getExpectationsFor($method)?->getExpectations()[0];
        $this->assertInstanceOf(Expectation::class, $expectation);

        return $expectation;
    }

    /** @return array{asset: Asset, cost: int, provider: string, model: string} */
    private function image(Asset $asset, int $cost): array
    {
        return ['asset' => $asset, 'cost' => $cost, 'provider' => 'fixture', 'model' => 'image'];
    }
}
