<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ChannelType;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\Jobs\ReadSitePalette;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
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

    /**
     * Reading the site again fills the swatches and touches nothing else.
     *
     * The gap this closes: the palette was only ever written by the onboarding
     * wizard, and the wizard refuses a launched project — so every project that
     * existed before this feature could never be offered a suggestion at all.
     */
    #[Test]
    public function reading_the_site_again_refreshes_the_colours_it_suggests(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/screenshot' => Http::response($this->pagePng(), 200)]);

        $this->project->forceFill([
            'website_url' => 'https://example.com',
            // The rest of the analysis, which the operator corrected by hand in
            // the wizard and did not ask to have re-guessed.
            'site_analysis' => [
                'name' => 'Cleaning Point',
                'description' => 'A Lisbon home-cleaning business.',
                'seed_keywords' => ['limpeza lisboa'],
            ],
        ])->save();

        $this->actingAs($this->operator)
            ->post('/brief/palette')
            ->assertRedirect('/brief');

        $analysis = $this->project->refresh()->site_analysis;

        $this->assertSame('#204040', $analysis['palette']['fill']);

        // Everything the wizard wrote survives. Re-running the whole analysis
        // would have replaced all of this, silently, behind a button that says
        // it reads colours.
        $this->assertSame('Cleaning Point', $analysis['name']);
        $this->assertSame('A Lisbon home-cleaning business.', $analysis['description']);
        $this->assertSame(['limpeza lisboa'], $analysis['seed_keywords']);
    }

    /**
     * A suggestion is still only a suggestion once it has been read.
     */
    #[Test]
    public function reading_the_site_does_not_write_the_colours_into_the_brief(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/screenshot' => Http::response($this->pagePng(), 200)]);

        $brief = BrandBrief::revise($this->project, ['brand_colour' => '#111111'], null);
        $this->project->forceFill(['website_url' => 'https://example.com'])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        // Offered on the screen, and nowhere near the live version.
        $this->assertSame('#111111', $brief->refresh()->brand_colour);

        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('palette.fill', '#204040')
                ->where('brief.brand_colour', '#111111')
                ->etc()
            );
    }

    /**
     * A read that finds nothing clears the last suggestion.
     *
     * Otherwise a stale swatch keeps offering a colour the engine no longer
     * stands behind — and a stale one looks exactly like a fresh one, so there
     * is nothing on the screen that could tell an operator which it is.
     */
    #[Test]
    public function a_read_that_finds_no_surface_withdraws_the_old_suggestion(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        // A page that is all photograph: nothing on it is painted.
        Http::fake(['*/screenshot' => Http::response($this->noisePng(), 200)]);

        $this->project->forceFill([
            'website_url' => 'https://example.com',
            'site_analysis' => ['palette' => ['fill' => '#b0a090', 'ink' => '#000000', 'accent' => null]],
        ])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        $this->assertNull($this->project->refresh()->site_analysis['palette']);
    }

    /**
     * A browser that could not open the page forgets nothing.
     *
     * The opposite case, and the reason the two are told apart: a site that was
     * down for a minute must not cost an operator a good suggestion they had
     * already been given.
     */
    #[Test]
    public function a_read_that_could_not_open_the_page_keeps_the_old_suggestion(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake(['*/screenshot' => Http::response(['message' => 'net::ERR_ABORTED'], 502)]);

        $this->project->forceFill([
            'website_url' => 'https://example.com',
            'site_analysis' => ['palette' => ['fill' => '#204040', 'ink' => '#f0f0f0', 'accent' => null]],
        ])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        $this->assertSame('#204040', $this->project->refresh()->site_analysis['palette']['fill']);
    }

    /**
     * The browser runs on the queue, not in the operator's request.
     *
     * {@see SiteScreenshot} allows a page 120 seconds to load, which is a PHP
     * worker held for two minutes by one click. What the request now owes the
     * operator is an acknowledgement: the work is queued and the screen is told
     * a read is running, both before the response is written.
     */
    #[Test]
    public function reading_the_site_is_queued_rather_than_done_in_the_request(): void
    {
        Queue::fake();
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        // Nothing should reach it: the browser is the job's business now.
        Http::fake();

        $this->project->forceFill(['website_url' => 'https://example.com'])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        Queue::assertPushed(ReadSitePalette::class);
        Http::assertNothingSent();

        $this->assertNotNull($this->project->refresh()->site_analysis['palette_reading_at']);
    }

    /**
     * A read in flight is on the screen, which is what the polling hangs off.
     */
    #[Test]
    public function the_screen_reports_a_read_that_is_still_running(): void
    {
        $this->project->forceFill([
            'site_analysis' => ['palette_reading_at' => now()->toIso8601String()],
        ])->save();

        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paletteReading', true)
                ->etc()
            );
    }

    /**
     * And a read that died takes the screen down with it unless the flag expires.
     *
     * The job lowers it on every path it can reach, including its own failure —
     * but a worker killed outright reaches none of them, and a flag stuck on is
     * a button disabled forever behind a spinner polling for an answer that is
     * never coming.
     */
    #[Test]
    public function a_read_that_never_finished_stops_being_reported_as_running(): void
    {
        $this->project->forceFill([
            'site_analysis' => ['palette_reading_at' => now()->subMinutes(30)->toIso8601String()],
        ])->save();

        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paletteReading', false)
                ->etc()
            );
    }

    /**
     * What the read concluded outlives the request that started it.
     *
     * A toast cannot carry this any more. The response that would have flashed
     * one is delivered while the browser is still opening the page, so the
     * answer is stored and printed beside the swatches instead — including the
     * one an operator most needs and a spinner cannot give them, which is that
     * the page was read and had nothing to offer.
     */
    #[Test]
    public function a_finished_read_says_what_it_found(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        // All photograph: read successfully, and nothing on it to suggest.
        Http::fake(['*/screenshot' => Http::response($this->noisePng(), 200)]);

        $this->project->forceFill(['website_url' => 'https://example.com'])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paletteReading', false)
                ->where('paletteOutcome.type', 'info')
                ->etc()
            );
    }

    /**
     * The palette rides with the brief, and versions with it.
     *
     * It is a {@see BrandBrief::VISUAL_FIELDS} entry rather than a loose setting
     * for the reason the rest of them are: a carousel drawn last month has to be
     * able to say which colours drew it, and a palette stored outside the
     * version history would silently re-answer that question every time somebody
     * edited it.
     */
    #[Test]
    public function the_palette_is_saved_on_the_brief_and_makes_a_new_version(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.']);

        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'brand_colour' => '#002954',
                'brand_ink' => '#ffffff',
                'brand_accent' => '#22cbc5',
                'brand_palette' => ['#0e8f89', '#1fa971'],
                'change_note' => 'The rest of the brand, from the site read.',
            ])
            ->assertRedirect(route('brief.edit'));

        $active = BrandBrief::activeFor($this->project);

        $this->assertNotNull($active);
        $this->assertSame(2, $active->version);
        $this->assertSame(['#0e8f89', '#1fa971'], $active->brand_palette);

        // And it comes back to the form it was typed into.
        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brief.brand_palette.0', '#0e8f89')
                ->etc()
            );
    }

    /**
     * A palette entry that is not a colour is refused, like every other colour.
     */
    #[Test]
    public function a_palette_entry_that_is_not_a_colour_is_rejected(): void
    {
        BrandBrief::revise($this->project, ['tone' => 'Warm.']);

        $this->actingAs($this->operator)
            ->put(route('brief.update'), [
                'brand_palette' => ['#0e8f89', 'rebeccapurple'],
            ])
            ->assertSessionHasErrors('brand_palette.1');

        // And nothing was written: a rejected edit does not make a version.
        $this->assertSame(1, BrandBrief::activeFor($this->project)?->version);
    }

    /**
     * Clearing it means "nothing to reach for", not a broken value.
     *
     * The same rule {@see BrandBrief::visualDefault()} already applies to a
     * cleared accent, and the state every brief written before the column
     * existed is in — which the fallbacks read as "carry on exactly as before".
     */
    #[Test]
    public function clearing_the_palette_stores_an_empty_list(): void
    {
        BrandBrief::revise($this->project, ['brand_palette' => ['#0e8f89']]);

        $this->actingAs($this->operator)
            ->put(route('brief.update'), ['tone' => 'Direct.'])
            ->assertRedirect(route('brief.edit'));

        $this->assertSame([], BrandBrief::activeFor($this->project)?->brand_palette);
    }

    /**
     * The whole chain, on the shape the renderer actually answers with.
     *
     * Everything above this fakes the older reply — the image and nothing else —
     * which is deliberate and worth keeping: the renderer is built and shipped
     * as its own container, so an app running ahead of it has to keep working
     * off the picture alone. This is the other half, and the one that gets the
     * brand's real values.
     */
    #[Test]
    public function a_read_takes_the_colours_the_stylesheet_declares(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        Http::fake(['*/screenshot' => Http::response([
            // A picture that would census to a green band, so a fill of #002954
            // can only have come from the declared colours below.
            'image' => base64_encode($this->pagePng()),
            'colours' => [
                ['hex' => '#ffffff', 'role' => 'background', 'weight' => 5635047],
                ['hex' => '#002954', 'role' => 'background', 'weight' => 1406534],
                ['hex' => '#22cbc5', 'role' => 'background', 'weight' => 49016],
                ['hex' => '#0e8f89', 'role' => 'text', 'weight' => 46],
            ],
            'fonts' => ['Poppins', 'Instrument Sans'],
        ], 200)]);

        $this->project->forceFill(['website_url' => 'https://example.com'])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        $this->actingAs($this->operator)
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The navy, exactly as declared. The census of the image above
                // would have answered with its green band.
                ->where('palette.fill', '#002954')
                ->where('palette.accent', '#22cbc5')
                // And the rest of the brand, which three slots have no room for.
                ->where('paletteColours.0', '#ffffff')
                ->where('paletteColours.1', '#002954')
                ->where('siteFont', 'Poppins')
                ->etc()
            );
    }

    #[Test]
    public function a_project_with_no_address_is_told_so_rather_than_failing(): void
    {
        config(['content_studio.renderer.url' => 'http://renderer:3020']);
        Http::fake();

        $this->project->forceFill(['website_url' => ''])->save();

        $this->actingAs($this->operator)->post('/brief/palette')->assertRedirect('/brief');

        // No browser was driven at nothing.
        Http::assertNothingSent();
        $this->assertNull($this->project->refresh()->site_analysis['palette'] ?? null);
    }

    #[Test]
    public function only_an_owner_may_ask_the_engine_to_read_the_site(): void
    {
        $operator = User::factory()->create();
        $this->project->users()->attach($operator, ['role' => 'operator']);

        $this->actingAs($operator)
            ->withSession(['project_id' => $this->project->getKey()])
            ->post('/brief/palette')
            ->assertForbidden();
    }

    /** A page that is nothing but photograph: noisy everywhere, painted nowhere. */
    private function noisePng(): string
    {
        $image = imagecreatetruecolor(320, 240);
        mt_srand(20260817);

        for ($y = 0; $y < 240; $y++) {
            for ($x = 0; $x < 320; $x++) {
                $shade = imagecolorallocate(
                    $image,
                    max(0, min(255, 176 + mt_rand(-45, 45))),
                    max(0, min(255, 160 + mt_rand(-45, 45))),
                    max(0, min(255, 144 + mt_rand(-45, 45))),
                );

                if ($shade !== false) {
                    imagesetpixel($image, $x, $y, $shade);
                }
            }
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** A white page with a green band across the top. */
    private function pagePng(): string
    {
        $image = imagecreatetruecolor(320, 240);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 47, 79, 67);

        if ($white === false || $green === false) {
            throw new RuntimeException('The test image ran out of colours.');
        }

        imagefilledrectangle($image, 0, 0, 320, 240, $white);
        imagefilledrectangle($image, 0, 0, 320, 70, $green);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
