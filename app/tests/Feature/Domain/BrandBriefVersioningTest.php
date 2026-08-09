<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criterion 1 of phase 2: changing the tone makes a new version, the old
 * one stays readable, and it knows which publications were made on it.
 */
final class BrandBriefVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
    }

    #[Test]
    public function the_first_revision_is_version_one_and_is_active(): void
    {
        $brief = BrandBrief::revise($this->project, [
            'positioning' => 'Cleaning, done by people who live here.',
            'tone' => 'Warm, plain, never salesy.',
        ]);

        $this->assertSame(1, $brief->version);
        $this->assertTrue($brief->is_active);
        $this->assertSame('Warm, plain, never salesy.', $brief->tone);
    }

    #[Test]
    public function changing_the_tone_creates_a_new_version_and_retires_the_old_one(): void
    {
        $first = BrandBrief::revise($this->project, [
            'positioning' => 'Cleaning, done by people who live here.',
            'tone' => 'Warm, plain, never salesy.',
            'competitors' => ['helpling.pt'],
        ]);

        $second = BrandBrief::revise($this->project, [
            'tone' => 'Direct and short.',
        ], 'Customers found the old voice waffly.');

        $this->assertSame(2, $second->version);
        $this->assertTrue($second->is_active);

        // The old row is still there, still says what it said, and is marked
        // as no longer the live one.
        $first->refresh();
        $this->assertFalse($first->is_active);
        $this->assertSame('Warm, plain, never salesy.', $first->tone);

        $this->assertSame(2, $this->project->brandBriefs()->count());
    }

    #[Test]
    public function a_partial_revision_carries_the_rest_of_the_brief_forward(): void
    {
        BrandBrief::revise($this->project, [
            'positioning' => 'Cleaning, done by people who live here.',
            'audience' => 'Lisbon flat owners who let short-term.',
            'competitors' => ['helpling.pt', 'zaask.pt'],
            'forbidden_topics' => ['discount wars'],
        ]);

        $second = BrandBrief::revise($this->project, ['tone' => 'Direct and short.']);

        // Editing the tone must not blank the competitor list. This is the
        // difference between "save a version" and "replace the document".
        $this->assertSame('Lisbon flat owners who let short-term.', $second->audience);
        $this->assertSame(['helpling.pt', 'zaask.pt'], $second->competitors);
        $this->assertSame(['discount wars'], $second->forbidden_topics);
    }

    #[Test]
    public function a_null_clears_a_field_instead_of_breaking_the_insert(): void
    {
        BrandBrief::revise($this->project, [
            'tone' => 'Warm.',
            'competitors' => ['helpling.pt'],
        ]);

        // Callers that never touch an HTTP request reach revise() directly, and
        // "this field is now empty" is a null from most of them too.
        $second = BrandBrief::revise($this->project, [
            'tone' => null,
            'competitors' => null,
        ]);

        $this->assertSame('', $second->tone);
        $this->assertSame([], $second->competitors);
    }

    #[Test]
    public function the_change_note_belongs_to_the_revision_that_introduced_it(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.'], 'initial fill');
        $second = BrandBrief::revise($this->project, ['tone' => 'Direct.'], 'too waffly');

        $this->assertSame('too waffly', $second->change_note);
        $this->assertSame('initial fill', $this->project->brandBriefs()->firstWhere('version', 1)?->change_note);
    }

    #[Test]
    public function an_old_version_knows_which_publications_were_made_on_it(): void
    {
        $first = BrandBrief::revise($this->project, ['tone' => 'Warm.']);

        $onFirst = ContentItem::factory()->published()->count(2)
            ->create(['brand_brief_id' => $first->getKey()]);

        $second = BrandBrief::revise($this->project, ['tone' => 'Direct.']);

        $onSecond = ContentItem::factory()->published()
            ->create(['brand_brief_id' => $second->getKey()]);

        $this->assertEqualsCanonicalizing(
            $onFirst->modelKeys(),
            $first->contentItems()->pluck('id')->all(),
        );

        $this->assertSame([$onSecond->getKey()], $second->contentItems()->pluck('id')->all());
    }

    #[Test]
    public function the_database_refuses_a_second_active_version(): void
    {
        $first = BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        BrandBrief::revise($this->project, ['tone' => 'Direct.']);

        // Refreshed first: the second revise() turned this row off in the
        // database but not in this instance, and re-setting a value the object
        // already holds makes the model non-dirty and the save a no-op.
        $first->refresh();

        $this->expectException(QueryException::class);

        // Not reachable through revise(); reachable through any future writer
        // that sets the flag directly, which is exactly why the rule is an
        // index and not a line of PHP.
        $first->forceFill(['is_active' => true])->save();
    }

    #[Test]
    public function two_projects_each_keep_their_own_active_version(): void
    {
        $other = Project::factory()->create();

        $mine = BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        $theirs = BrandBrief::revise($other, ['tone' => 'Blunt.']);

        // The partial index is per project — one active brief each, not one in
        // the whole table.
        $this->assertTrue($mine->is_active);
        $this->assertTrue($theirs->is_active);
        $this->assertSame(1, $mine->version);
        $this->assertSame(1, $theirs->version);
    }

    #[Test]
    public function versions_are_numbered_per_project(): void
    {
        $other = Project::factory()->create();

        BrandBrief::revise($this->project, ['tone' => 'a']);
        BrandBrief::revise($this->project, ['tone' => 'b']);
        $theirFirst = BrandBrief::revise($other, ['tone' => 'c']);

        $this->assertSame(1, $theirFirst->version, 'A new project starts at v1, not at the global next.');
    }

    #[Test]
    public function active_for_returns_the_live_version(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        $second = BrandBrief::revise($this->project, ['tone' => 'Direct.']);

        $this->assertTrue($second->is($this->project->brandBrief()->first()));
        $this->assertTrue($second->is(BrandBrief::activeFor($this->project)));
    }

    #[Test]
    public function revising_works_with_no_project_current(): void
    {
        // Seeders and maintenance commands run outside a tenant. Without the
        // unscoped update inside revise(), the old row stays active here and
        // the project ends up with two live briefs.
        app(CurrentProject::class)->forget();

        BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        $second = BrandBrief::revise($this->project, ['tone' => 'Direct.']);

        $this->assertSame(2, $second->version);
        $this->assertSame(
            1,
            BrandBrief::acrossProjects()
                ->where('project_id', $this->project->getKey())
                ->where('is_active', true)
                ->count(),
        );
    }

    // ------------------------------------------------------- compileToPrompt

    #[Test]
    public function compiling_the_same_brief_twice_gives_the_same_bytes(): void
    {
        $brief = BrandBrief::factory()->create();

        // Determinism is the contract phase 3 depends on: a prompt that varies
        // between runs makes two runs of one pipeline incomparable.
        $this->assertSame($brief->compileToPrompt(), $brief->compileToPrompt());
        $this->assertSame($brief->compileToPrompt(), $brief->fresh()?->compileToPrompt());
    }

    #[Test]
    public function the_compiled_prompt_carries_the_brief_and_its_order(): void
    {
        $brief = BrandBrief::factory()->create([
            'positioning' => 'Cleaning, done by people who live here.',
            'audience' => 'Lisbon flat owners.',
            'tone' => 'Warm and plain.',
            'visual_language' => 'Daylight, no stock smiles.',
            'forbidden_topics' => ['discount wars', 'competitor bashing'],
            'examples_liked' => ['We show up at 9 and we are gone by 11.'],
            'examples_disliked' => ['Unlock the secret to a spotless home!'],
            'competitors' => ['helpling.pt'],
        ]);

        $compiled = $brief->compileToPrompt();

        $this->assertStringContainsString('## Positioning', $compiled);
        $this->assertStringContainsString('Cleaning, done by people who live here.', $compiled);
        $this->assertStringContainsString("- discount wars\n- competitor bashing", $compiled);

        $this->assertLessThan(
            strpos($compiled, '## Tone of voice'),
            strpos($compiled, '## Positioning'),
            'Section order is part of what makes the output stable.',
        );
    }

    #[Test]
    public function empty_sections_are_dropped_rather_than_emitted_blank(): void
    {
        $brief = BrandBrief::factory()->blank()->create(['tone' => 'Warm and plain.']);

        $compiled = $brief->compileToPrompt();

        // An empty "## Competitors" heading is not neutral in a prompt — it
        // tells the model the project has none.
        $this->assertSame("## Tone of voice\nWarm and plain.", $compiled);
    }
}
