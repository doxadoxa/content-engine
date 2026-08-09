<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ChannelType;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * §2.3 — the three screens phase 2 adds, as Inertia pages rather than a second
 * admin panel (see the note at the end of the README).
 */
final class BrandBriefScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->multilingual()->create();
        $this->operator = User::factory()->create();
        $this->operator->projects()->attach($this->project, ['role' => 'owner']);

        app(CurrentProject::class)->set($this->project);
    }

    // -------------------------------------------------------------- brief

    #[Test]
    public function the_brief_screen_shows_the_live_version_and_its_history(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.'], 'first pass');
        BrandBrief::revise($this->project, ['tone' => 'Direct.'], 'too waffly');

        $this->actingAs($this->operator)
            ->get(route('brief.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('brief/edit')
                ->where('brief.tone', 'Direct.')
                ->has('versions', 2)
                // Newest first: the history reads top-down as "what changed
                // most recently".
                ->where('versions.0.version', 2)
                ->where('versions.0.is_active', true)
                ->where('versions.1.version', 1)
                ->where('versions.1.is_active', false)
                ->where('versions.1.change_note', 'first pass')
            );
    }

    #[Test]
    public function the_history_counts_what_was_published_on_each_version(): void
    {
        $first = BrandBrief::revise($this->project, ['tone' => 'Warm.']);
        ContentItem::factory()->published()->count(2)
            ->create(['brand_brief_id' => $first->getKey()]);

        BrandBrief::revise($this->project, ['tone' => 'Direct.']);

        $this->actingAs($this->operator)
            ->get(route('brief.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('versions.0.publications', 0)
                ->where('versions.1.publications', 2)
            );
    }

    #[Test]
    public function an_empty_project_gets_the_form_and_no_history(): void
    {
        $this->actingAs($this->operator)
            ->get(route('brief.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brief', null)
                ->has('versions', 0)
            );
    }

    #[Test]
    public function saving_the_brief_creates_a_new_version(): void
    {
        BrandBrief::revise($this->project, [
            'tone' => 'Warm.',
            'competitors' => ['helpling.pt'],
        ]);

        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'positioning' => 'Cleaning, done by people who live here.',
                'tone' => 'Direct and short.',
                'competitors' => "helpling.pt\nzaask.pt",
                'change_note' => 'Customers found the old voice waffly.',
            ])
            ->assertRedirect(route('brief.edit'));

        $active = BrandBrief::activeFor($this->project);

        $this->assertNotNull($active);
        $this->assertSame(2, $active->version);
        $this->assertSame('Direct and short.', $active->tone);
        $this->assertSame('Customers found the old voice waffly.', $active->change_note);

        // The line-separated field arrived as text and is stored as a list.
        $this->assertSame(['helpling.pt', 'zaask.pt'], $active->competitors);
    }

    #[Test]
    public function blank_lines_and_padding_are_dropped_from_list_fields(): void
    {
        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'tone' => 'Warm.',
                'forbidden_topics' => "  discount wars \n\n\n  competitor bashing\n",
            ])
            ->assertRedirect();

        $this->assertSame(
            ['discount wars', 'competitor bashing'],
            BrandBrief::activeFor($this->project)?->forbidden_topics,
        );
    }

    #[Test]
    public function clearing_a_field_stores_it_empty_rather_than_failing(): void
    {
        BrandBrief::revise($this->project, [
            'tone' => 'Warm.',
            'positioning' => 'Cleaning, done by people who live here.',
        ]);

        // Emptying a textarea reaches the server as null, not as '': Laravel's
        // global ConvertEmptyStringsToNull rewrites it before validation. Every
        // text column here is NOT NULL, so an unguarded null is a 500 on the
        // most ordinary edit there is.
        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'positioning' => 'Cleaning, done by people who live here.',
                'tone' => '',
            ])
            ->assertRedirect(route('brief.edit'));

        $active = BrandBrief::activeFor($this->project);

        $this->assertNotNull($active);
        $this->assertSame('', $active->tone);
        $this->assertSame('Cleaning, done by people who live here.', $active->positioning);
    }

    #[Test]
    public function clearing_every_field_at_once_is_survivable(): void
    {
        BrandBrief::revise($this->project, [
            'tone' => 'Warm.',
            'competitors' => ['helpling.pt'],
            'forbidden_topics' => ['discount wars'],
        ]);

        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'positioning' => '',
                'audience' => '',
                'tone' => '',
                'visual_language' => '',
                'forbidden_topics' => '',
                'examples_liked' => '',
                'examples_disliked' => '',
                'competitors' => '',
            ])
            ->assertRedirect(route('brief.edit'));

        $active = BrandBrief::activeFor($this->project);

        $this->assertNotNull($active);
        $this->assertSame('', $active->tone);
        $this->assertSame([], $active->competitors);
        $this->assertSame([], $active->forbidden_topics);

        // An empty brief compiles to an empty prompt rather than to headings
        // with nothing under them.
        $this->assertSame('', $active->compileToPrompt());
    }

    #[Test]
    public function an_over_long_field_is_rejected_without_creating_a_version(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.']);

        $this->actingAs($this->operator)
            ->from(route('brief.edit'))
            ->put(route('brief.update'), ['tone' => str_repeat('a', 5001)])
            ->assertRedirect(route('brief.edit'))
            ->assertSessionHasErrors('tone');

        // A rejected save must not leave a version behind — the history is
        // meant to be a list of decisions, not of attempts.
        $this->assertSame(1, $this->project->brandBriefs()->count());
    }

    #[Test]
    public function the_brief_screen_needs_a_signed_in_operator(): void
    {
        $this->get(route('brief.edit'))->assertRedirect(route('login'));
        $this->put(route('brief.update'), [])->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------ channels

    #[Test]
    public function the_channel_list_shows_configuration_but_never_the_secret(): void
    {
        Channel::factory()->create([
            'name' => 'Blog receiver',
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://example.test/hook'],
            'secret' => 'super-secret-token',
        ]);

        $response = $this->actingAs($this->operator)->get(route('channels.index'));

        $response->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('channels/index')
                ->has('channels', 1)
                ->where('channels.0.name', 'Blog receiver')
                ->where('channels.0.type_label', 'Webhook')
                ->where('channels.0.target', 'https://example.test/hook')
                ->where('channels.0.has_secret', true)
                ->missing('channels.0.secret')
            );

        // Belt and braces: the token must not appear anywhere in the payload,
        // whatever key it might have slipped in under.
        $response->assertDontSee('super-secret-token', false);
    }

    #[Test]
    public function the_channel_list_is_scoped_to_the_current_project(): void
    {
        Channel::factory()->create(['name' => 'Mine']);

        $other = Project::factory()->create();
        app(CurrentProject::class)->run($other, fn () => Channel::factory()->create(['name' => 'Theirs']));

        $this->actingAs($this->operator)
            ->get(route('channels.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('channels', 1)
                ->where('channels.0.name', 'Mine')
            );
    }

    // ------------------------------------------------------------- content

    #[Test]
    public function the_content_list_shows_one_row_per_unit_not_per_locale(): void
    {
        $pt = ContentItem::factory()->locale('pt-PT')->create([
            'title' => 'Como limpar janelas',
            'slug' => 'como-limpar-janelas',
        ]);
        $pt->addLocale('en', 'how-to-clean-windows', 'How to clean windows');
        ContentItem::factory()->count(3)->derivedFrom($pt)->create();

        $this->actingAs($this->operator)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('content/index')
                // Two locale rows and three derivatives are one unit.
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Como limpar janelas')
                ->where('items.data.0.locales', ['en', 'pt-PT'])
                ->where('items.data.0.derivatives', 3)
            );
    }

    #[Test]
    public function the_content_list_prefers_the_projects_own_language(): void
    {
        // The project's default is pt-PT, so the row an operator recognises is
        // the Portuguese one even though the English row was created first.
        $en = ContentItem::factory()->locale('en')->create(['title' => 'How to clean windows']);
        $en->addLocale('pt-PT', 'como-limpar-janelas', 'Como limpar janelas');

        $this->actingAs($this->operator)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.data.0.title', 'Como limpar janelas')
                ->where('items.data.0.locale', 'pt-PT')
            );
    }

    #[Test]
    public function the_content_list_is_empty_and_fine_with_it(): void
    {
        $this->actingAs($this->operator)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('items.data', 0));
    }

    #[Test]
    public function the_content_list_paginates_units_instead_of_loading_the_project_history(): void
    {
        ContentItem::factory()->count(26)->create();

        $this->actingAs($this->operator)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 25)
                ->where('items.total', 26)
                ->where('items.current_page', 1)
                ->where('items.next_page_url', fn (mixed $url): bool => is_string($url))
            );

        $this->actingAs($this->operator)
            ->get(route('content.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 1)
                ->where('items.current_page', 2)
            );
    }
}
