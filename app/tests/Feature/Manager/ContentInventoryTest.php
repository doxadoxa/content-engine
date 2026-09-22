<?php

declare(strict_types=1);

namespace Tests\Feature\Manager;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContentInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['default_locale' => 'en']);
        $owner = User::factory()->create();
        $this->project->users()->attach($owner, ['role' => 'owner']);
        app(CurrentProject::class)->set($this->project);
        $this->actingAs($owner)->withSession(['project_id' => $this->project->id]);
    }

    #[Test]
    public function title_search_is_case_insensitive_per_unit_and_keeps_the_matching_translation_visible(): void
    {
        $group = (string) Str::ulid();
        ContentItem::factory()->draft()->inGroup($group, 'en')->create([
            'title' => 'How to choose cleaning products',
        ]);
        $portuguese = ContentItem::factory()->draft()->inGroup($group, 'pt')->create([
            'title' => 'Produtos de Limpeza para Obras',
        ]);
        ContentItem::factory()->draft()->create(['title' => 'A different article']);

        $this->get('/content?view=review&search=produtos')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('content/index')
                ->where('view', 'review')
                ->where('search', 'produtos')
                ->where('items.total', 1)
                ->where('items.data.0.id', $portuguese->id)
                ->where('items.data.0.title', 'Produtos de Limpeza para Obras')
                ->where('items.data.0.locales', ['en', 'pt'])
                ->where('status_counts.all', 1)
                ->where('status_counts.review', 1)
                ->where('status_counts.writing', 0)
                ->where('status_counts.scheduled', 0)
                ->where('status_counts.published', 0));
    }

    #[Test]
    public function content_pagination_retains_the_active_title_search_and_status_filter(): void
    {
        foreach (range(1, 13) as $number) {
            ContentItem::factory()->draft()->create([
                'title' => "Cleaning question {$number}",
            ]);
        }

        $this->get('/content?view=review&search=cleaning&page=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.current_page', 2)
                ->where('items.total', 13)
                ->where(
                    'items.prev_page_url',
                    url('/content?view=review&search=cleaning&page=1'),
                ));
    }

    #[Test]
    public function equal_creation_times_have_a_stable_group_order_across_content_pages(): void
    {
        $createdAt = now()->subDay();
        $units = [];
        foreach (range(1, 13) as $number) {
            $group = (string) Str::ulid();
            $item = ContentItem::factory()->draft()->inGroup($group, 'en')->create([
                'title' => "Imported article {$number}",
            ]);
            $item->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
            $units[$group] = $item->id;
        }

        $first = $this->get('/content?view=review')
            ->assertOk()
            ->viewData('page')['props']['items']['data'];
        $second = $this->get('/content?view=review&page=2')
            ->assertOk()
            ->viewData('page')['props']['items']['data'];

        $expected = collect($units)->sortKeysDesc()->values()->all();
        $firstIds = array_column($first, 'id');
        $secondIds = array_column($second, 'id');

        $this->assertSame(array_slice($expected, 0, 12), $firstIds);
        $this->assertSame([$expected[12]], $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    #[Test]
    public function title_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        $literal = ContentItem::factory()->draft()->create([
            'title' => 'Cleaning price 100%_ guaranteed',
        ]);
        ContentItem::factory()->draft()->create([
            'title' => 'Cleaning price 100abc guaranteed',
        ]);

        $this->get('/content?search=100%25_')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.total', 1)
                ->where('items.data.0.id', $literal->id)
                ->where('status_counts.all', 1));
    }

    #[Test]
    public function an_article_only_accepts_a_local_content_list_return_target(): void
    {
        $item = ContentItem::factory()->inState(ContentItemState::Draft)->create();
        $returnTo = '/content?view=review&search=cleaning&page=2';

        $this->get('/content/'.$item->id.'?'.http_build_query(['return_to' => $returnTo]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('content/show')
                ->where('return_to', $returnTo));

        $this->get('/content/'.$item->id.'?return_to=https%3A%2F%2Fexample.test%2Fcontent')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('return_to', null));
    }
}
