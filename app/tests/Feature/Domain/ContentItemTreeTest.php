<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exit criterion 3 of phase 2: a unit with two locales and three derivatives is
 * built and read without a query per child.
 *
 * The count is asserted exactly rather than as "small". A bound like `< 10`
 * passes just as happily when a relation quietly starts lazy-loading and the
 * tree happens to be short, which is the shape the dashboard will have in
 * phase 7 — one row on screen during a test, forty in front of an operator.
 */
final class ContentItemTreeTest extends TestCase
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
    public function a_unit_is_built_with_two_locales_and_three_derivatives(): void
    {
        [$pt, $en] = $this->unitWithTwoLocalesAndThreeDerivatives();

        $this->assertSame($pt->locale_group_id, $en->locale_group_id);
        $this->assertSame(2, $pt->localeVariants()->count());
        $this->assertSame(3, $pt->derivatives()->count());

        // Derivatives inherit the parent's entities and links (§2).
        $this->assertSame($pt->entities, $pt->derivatives()->first()?->entities);
    }

    #[Test]
    public function the_whole_tree_is_read_in_a_fixed_number_of_queries(): void
    {
        $this->unitWithTwoLocalesAndThreeDerivatives();

        $queries = $this->countQueries(function (): void {
            $roots = ContentItem::query()->roots()->withTree()->get();

            // Touch everything the dashboard would touch. If any of it were
            // lazy, the count below would move with the number of children.
            foreach ($roots as $root) {
                $root->localeVariants->each(fn (ContentItem $item) => $item->locale);
                $root->derivatives->each(fn (ContentItem $item) => $item->title);
            }
        });

        // One for the roots, one for every locale variant, one for every
        // derivative. Three, whatever the tree's size.
        $this->assertSame(3, $queries);
    }

    #[Test]
    public function the_count_does_not_grow_with_the_tree(): void
    {
        $this->unitWithTwoLocalesAndThreeDerivatives();

        $small = $this->countQueries(fn () => $this->readAllTrees());

        // A second unit, twice as wide as the first.
        $other = ContentItem::factory()->locale('pt-PT')->create();
        $other->addLocale('en', 'second-unit-en', 'Second unit');
        ContentItem::factory()->count(6)->derivedFrom($other)->create();

        $large = $this->countQueries(fn () => $this->readAllTrees());

        $this->assertSame($small, $large, 'Reading twice the tree took more queries — something is lazy.');
    }

    #[Test]
    public function locale_variants_include_the_row_itself(): void
    {
        [$pt, $en] = $this->unitWithTwoLocalesAndThreeDerivatives();

        // "The unit in every language" is the useful question, and it is the
        // same answer whichever locale row you ask it from.
        $this->assertEqualsCanonicalizing(
            [$pt->getKey(), $en->getKey()],
            $pt->localeVariants()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$pt->getKey(), $en->getKey()],
            $en->localeVariants()->pluck('id')->all(),
        );
    }

    #[Test]
    public function roots_excludes_derivatives(): void
    {
        $this->unitWithTwoLocalesAndThreeDerivatives();

        // Two locale rows are roots; the three social posts are not.
        $this->assertSame(2, ContentItem::query()->roots()->count());
        $this->assertSame(5, ContentItem::query()->count());
    }

    #[Test]
    public function a_derivative_knows_it_is_one(): void
    {
        [$pt] = $this->unitWithTwoLocalesAndThreeDerivatives();

        $derivative = $pt->derivatives()->first();

        $this->assertNotNull($derivative);
        $this->assertTrue($derivative->isDerivative());
        $this->assertFalse($pt->isDerivative());
        $this->assertTrue($pt->is($derivative->parent));
    }

    /**
     * @return array{ContentItem, ContentItem}
     */
    private function unitWithTwoLocalesAndThreeDerivatives(): array
    {
        $pt = ContentItem::factory()->locale('pt-PT')->create([
            'title' => 'Como limpar janelas',
            'slug' => 'como-limpar-janelas',
        ]);

        $en = $pt->addLocale('en', 'how-to-clean-windows', 'How to clean windows');

        ContentItem::factory()->count(3)->derivedFrom($pt)->create();

        return [$pt, $en];
    }

    private function readAllTrees(): void
    {
        ContentItem::query()->roots()->withTree()->get()->each(function (ContentItem $root): void {
            $root->localeVariants->each(fn (ContentItem $item) => $item->locale);
            $root->derivatives->each(fn (ContentItem $item) => $item->title);
        });
    }

    /**
     * The query log rather than DB::listen: a listener cannot be detached, so
     * calling this twice in one test leaves the first one attached and counting
     * into a variable nobody reads. That happens to be harmless here and stops
     * being harmless the moment someone counts a second time and compares.
     */
    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
