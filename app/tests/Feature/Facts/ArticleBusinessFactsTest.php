<?php

declare(strict_types=1);

namespace Tests\Feature\Facts;

use App\Ai\Contracts\ModelGateway;
use App\Content\ArticleBusinessFacts;
use App\Models\BusinessFact;
use App\Models\BusinessFactVersion;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Core\StepContext;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ArticleBusinessFactsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function writing_and_checking_share_pinned_confirmed_facts_and_expiry_blocks_publication(): void
    {
        $project = Project::factory()->create();
        app(CurrentProject::class)->set($project);
        $item = ContentItem::factory()->draft()->create();
        $fact = $this->fact('Confirmed service area', 'Lisbon only.');
        $this->fact('Unconfirmed price', 'An unconfirmed price of 700 euros.', false);
        $context = $this->context($project, $item);
        $service = app(ArticleBusinessFacts::class);
        $prompt = $service->compile($context, $item);
        $this->assertStringContainsString('Lisbon only.', $prompt);
        $this->assertStringNotContainsString('700 euros', $prompt);
        $this->assertSame($prompt, $service->pinnedPrompt($context));
        $this->assertNotNull($service->refusal($item));
        $service->seal($context, $item);
        $this->assertNull($service->refusal($item));
        $this->travel(2)->months();
        $this->assertNotNull($service->refusal($item));
        $this->assertSame($prompt, $service->compile($context, $item));
        $this->assertSame($fact->current_version_id, DB::table('article_business_fact_references')->sole()->fact_version_id);
    }

    #[Test]
    public function changed_facts_and_failed_rewrites_cannot_reuse_a_previous_drafts_approval(): void
    {
        $project = Project::factory()->create();
        app(CurrentProject::class)->set($project);
        $item = ContentItem::factory()->draft()->create();
        $fact = $this->fact('Area', 'Lisbon.');
        $service = app(ArticleBusinessFacts::class);
        $context = $this->context($project, $item);
        $service->compile($context, $item);
        $service->seal($context, $item);
        $fact->forceFill(['current_version_id' => null])->save();
        $this->assertNotNull($service->refusal($item));
        $next = $this->context($project, $item);
        $service->compile($next, $item);
        $this->assertNotNull($service->refusal($item), 'A failed rewrite must not make old content look newly checked.');
        $service->seal($next, $item);
        $this->assertNull($service->refusal($item));
        $item->forceFill(['summary' => 'A different claim after writing finished.'])->save();
        $this->assertNotNull($service->refusal($item));
    }

    #[Test]
    public function an_empty_business_register_does_not_prevent_general_articles_and_never_includes_another_project(): void
    {
        app(CurrentProject::class)->set(Project::factory()->create());
        $this->fact('Private fact', 'Other business private statement.');
        $project = Project::factory()->create();
        app(CurrentProject::class)->set($project);
        $item = ContentItem::factory()->draft()->create();
        $context = $this->context($project, $item);
        $service = app(ArticleBusinessFacts::class);
        $prompt = $service->compile($context, $item);
        $this->assertStringNotContainsString('Other business private statement.', $prompt);
        $this->assertStringContainsString('General educational articles can still be written.', $prompt);
        $service->seal($context, $item);
        $this->assertNull($service->refusal($item));
    }

    #[Test]
    public function changing_published_schema_or_link_claims_invalidates_the_checked_article(): void
    {
        $project = Project::factory()->create();
        app(CurrentProject::class)->set($project);
        $service = app(ArticleBusinessFacts::class);
        foreach (['json_ld', 'faq_json_ld', 'author', 'internal_links'] as $field) {
            $item = ContentItem::factory()->draft()->create();
            $context = $this->context($project, $item);
            $service->compile($context, $item);
            $service->seal($context, $item);
            $this->assertNull($service->refusal($item));
            $item->forceFill([$field => ['text' => 'An unchecked new business claim.']])->save();
            $this->assertNotNull($service->refusal($item), $field.' must be covered by the article seal.');
        }
    }

    private function context(Project $project, ContentItem $item): StepContext
    {
        return new StepContext(PipelineRun::factory()->create(['content_item_id' => $item->id]), $project, [], [], [], app(ModelGateway::class));
    }

    private function fact(string $name, string $statement, bool $confirmed = true): BusinessFact
    {
        $fact = BusinessFact::query()->create(['name' => $name]);
        $owner = User::factory()->create();
        $version = BusinessFactVersion::query()->create(['business_fact_id' => $fact->id, 'version' => 1, 'statement' => $statement, 'source_note' => 'Owner supplied.', 'status' => $confirmed ? 'confirmed' : 'draft', 'confirmed_by' => $confirmed ? $owner->id : null, 'confirmed_at' => $confirmed ? now() : null, 'review_due_at' => now()->addMonth()]);
        $fact->forceFill(['current_version_id' => $version->id])->save();

        return $fact;
    }
}
