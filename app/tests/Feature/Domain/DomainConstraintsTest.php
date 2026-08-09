<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Interaction;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\Signal;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §2.2 — the rules that phase 2 puts in the database rather than in code.
 *
 * Each of these is reachable from PHP by writing the wrong thing on purpose,
 * which is the point: a rule enforced only by the one method that currently
 * writes the row is a rule that lasts until the second writer.
 */
final class DomainConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->multilingual()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function a_locale_group_holds_each_locale_once(): void
    {
        $pt = ContentItem::factory()->locale('pt-PT')->create();

        $this->expectException(QueryException::class);

        // A second Portuguese row in the same group makes "this unit in PT" a
        // question with two answers.
        ContentItem::factory()->inGroup($pt->locale_group_id, 'pt-PT')->create();
    }

    #[Test]
    public function a_locale_group_accepts_a_different_locale(): void
    {
        $pt = ContentItem::factory()->locale('pt-PT')->create();

        $en = ContentItem::factory()->inGroup($pt->locale_group_id, 'en')->create();

        $this->assertSame($pt->locale_group_id, $en->locale_group_id);
    }

    #[Test]
    public function a_slug_is_unique_per_project_and_locale(): void
    {
        ContentItem::factory()->create(['slug' => 'how-to-clean-windows', 'locale' => 'en']);

        $this->expectException(QueryException::class);

        ContentItem::factory()->create(['slug' => 'how-to-clean-windows', 'locale' => 'en']);
    }

    #[Test]
    public function the_same_slug_is_free_in_another_locale(): void
    {
        ContentItem::factory()->create(['slug' => 'janelas', 'locale' => 'pt-PT']);
        $en = ContentItem::factory()->create(['slug' => 'janelas', 'locale' => 'en']);

        // Same word, two languages, two URLs. Refusing this would force a
        // suffix onto one of them for no reason.
        $this->assertSame('janelas', $en->slug);
    }

    #[Test]
    public function the_same_slug_is_free_in_another_project(): void
    {
        ContentItem::factory()->create(['slug' => 'pricing', 'locale' => 'en']);

        $other = Project::factory()->create();

        $theirs = app(CurrentProject::class)->run(
            $other,
            fn (): ContentItem => ContentItem::factory()->create(['slug' => 'pricing', 'locale' => 'en']),
        );

        $this->assertSame('pricing', $theirs->slug);
    }

    #[Test]
    public function a_parent_with_derivatives_cannot_be_deleted(): void
    {
        $parent = ContentItem::factory()->create();
        ContentItem::factory()->derivedFrom($parent)->create();

        $this->expectException(QueryException::class);

        // Deleting the article out from under its social posts would leave
        // rows claiming to be derived from something that no longer exists.
        $parent->delete();
    }

    #[Test]
    public function a_parent_can_be_deleted_once_its_derivatives_are_gone(): void
    {
        $parent = ContentItem::factory()->create();
        $derivative = ContentItem::factory()->derivedFrom($parent)->create();

        $derivative->delete();
        $parent->delete();

        $this->assertSame(0, ContentItem::query()->count());
    }

    #[Test]
    public function deleting_a_project_takes_its_whole_tree_with_it(): void
    {
        $plan = ContentPlan::factory()->create();
        $brief = BrandBrief::revise($this->project, ['tone' => 'Warm.']);

        $parent = ContentItem::factory()->create([
            'content_plan_id' => $plan->getKey(),
            'brand_brief_id' => $brief->getKey(),
        ]);
        ContentItem::factory()->count(2)->derivedFrom($parent)->create();
        Asset::factory()->hero()->for($parent, 'contentItem')->create();
        Channel::factory()->create();

        // The social tables of §3 are part of the tree too, and their cascade
        // is worth asserting rather than assuming: a signal is referenced by
        // both a unit and an interaction, so "delete the project" only works if
        // every one of those references gives way in the same statement.
        $signal = Signal::factory()->create();
        Interaction::factory()->create(['signal_id' => $signal->getKey()]);
        ProjectState::factory()->count(2)->create();

        // The parent/child and brief foreign keys are NO ACTION rather than
        // RESTRICT precisely so this one statement still works: everything
        // referencing and referenced goes in the same breath.
        $this->project->delete();

        $this->assertSame(0, ContentItem::acrossProjects()->count());
        $this->assertSame(0, Asset::acrossProjects()->count());
        $this->assertSame(0, Channel::acrossProjects()->count());
        $this->assertSame(0, ContentPlan::acrossProjects()->count());
        $this->assertSame(0, BrandBrief::acrossProjects()->count());
        $this->assertSame(0, Signal::acrossProjects()->count());
        $this->assertSame(0, Interaction::acrossProjects()->count());
        $this->assertSame(0, ProjectState::acrossProjects()->count());
    }

    #[Test]
    public function a_brief_that_wrote_something_cannot_be_deleted_on_its_own(): void
    {
        $brief = BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        ContentItem::factory()->published()->create(['brand_brief_id' => $brief->getKey()]);

        $this->expectException(QueryException::class);

        // "Старые публикации знают, на какой версии сделаны" only holds if the
        // version cannot be deleted while a publication points at it.
        $brief->delete();
    }

    #[Test]
    public function a_unit_has_at_most_one_hero_image(): void
    {
        $item = ContentItem::factory()->create();

        Asset::factory()->hero()->for($item, 'contentItem')->create();

        $this->expectException(QueryException::class);

        Asset::factory()->hero()->for($item, 'contentItem')->create();
    }

    #[Test]
    public function a_unit_may_have_many_inline_images(): void
    {
        $item = ContentItem::factory()->create();

        Asset::factory()->hero()->for($item, 'contentItem')->create();
        Asset::factory()->count(3)->inline()->for($item, 'contentItem')->create();

        $this->assertSame(4, $item->assets()->count());
    }

    #[Test]
    public function one_plan_per_project_per_month(): void
    {
        ContentPlan::factory()->forMonth('2026-09-01')->create();

        $this->expectException(QueryException::class);

        ContentPlan::factory()->forMonth('2026-09-15')->create();
    }

    #[Test]
    public function every_tenant_table_refuses_a_null_project(): void
    {
        // The three social tables are on the list for the reason the list
        // exists: the tenant scope is a global scope in PHP, so a row that can
        // hold a null project is a row a raw insert or a job with no tenant can
        // put outside every project and inside none.
        $tables = [
            'brand_briefs', 'channels', 'content_plans', 'content_items', 'assets',
            'pipeline_runs', 'webhook_deliveries', 'signals', 'interactions', 'project_states',
        ];

        foreach ($tables as $table) {
            $nullable = DB::selectOne(
                'select is_nullable from information_schema.columns
                 where table_name = ? and column_name = ?',
                [$table, 'project_id'],
            );

            $this->assertNotNull($nullable, "{$table} has no project_id column.");
            $this->assertSame('NO', $nullable->is_nullable, "{$table}.project_id is nullable.");
        }
    }

    #[Test]
    public function a_channel_secret_is_ciphertext_at_rest(): void
    {
        $channel = Channel::factory()->create(['secret' => 'super-secret-token']);

        $stored = DB::selectOne('select secret from channels where id = ?', [$channel->getKey()]);

        $this->assertNotNull($stored);
        $this->assertNotSame('super-secret-token', $stored->secret);
        $this->assertStringNotContainsString('super-secret-token', (string) $stored->secret);

        // …and still readable through the model.
        $this->assertSame('super-secret-token', $channel->fresh()?->secret);
    }

    #[Test]
    public function a_channel_secret_stays_out_of_serialised_output(): void
    {
        $channel = Channel::factory()->create(['secret' => 'super-secret-token']);

        // The cast decrypts on read, so anything that serialises a channel —
        // an Inertia prop, a queue payload, a log line — would carry the token
        // in the clear without $hidden.
        $this->assertArrayNotHasKey('secret', $channel->toArray());
        $this->assertStringNotContainsString('super-secret-token', (string) json_encode($channel));
        $this->assertTrue($channel->hasSecret());
    }
}
