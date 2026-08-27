<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * The published documents, and the two ways they can quietly become false.
 *
 * The first is access: a privacy policy behind a login is not a privacy policy,
 * and the cookie banner links to these pages from the landing screen, so a
 * stray `auth` middleware would break consent collection for everybody who has
 * not signed up yet.
 *
 * The second is drift. The cookie table and the entity block are rendered from
 * config/legal.php, so the risk is not that the page disagrees with the config
 * — it is that the config disagrees with the application. Hence the assertions
 * below that compare the published inventory against what the framework is
 * actually configured to set.
 */
final class LegalPagesTest extends TestCase
{
    /*
     * Opt-in in this suite rather than inherited, and this class needs it: the
     * signed-in cases below create users, and without a rollback they survive
     * into whatever runs next — which is how they were first noticed, by
     * SeedingTest counting four users where the seeder had made one.
     */
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documents(): array
    {
        return [
            'terms' => ['/terms', 'legal/terms'],
            'privacy' => ['/privacy', 'legal/privacy'],
            'cookies' => ['/cookies', 'legal/cookies'],
        ];
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_serves_each_document_to_somebody_with_no_account(string $path, string $component): void
    {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    #[Test]
    #[DataProvider('documents')]
    public function it_identifies_the_controller_on_every_document(string $path, string $component): void
    {
        $this->get($path)->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('entity.name', 'Courtly Ltd')
            ->where('entity.companyNumber', '17009343')
            ->where('entity.jurisdiction', 'England and Wales')
            ->where('entity.address', '86-90 Paul Street, London, EC2A 4NE, England')
            ->has('entity.email')
            ->has('updated')
            ->etc());
    }

    /*
     * The drift test that matters most. Rename the app or set SESSION_COOKIE
     * and the cookie policy is publishing a name the browser never sees.
     */
    #[Test]
    public function the_cookie_policy_lists_the_session_cookie_this_app_actually_sets(): void
    {
        $published = array_column((array) config('legal.cookies'), 'name');

        $this->assertContains(
            (string) config('session.cookie'),
            $published,
            'The session cookie is missing from config/legal.cookies, so the published cookie policy is incomplete.',
        );
    }

    #[Test]
    public function every_published_cookie_falls_in_a_category_the_banner_understands(): void
    {
        $known = ['essential', 'preferences', 'analytics', 'marketing'];

        foreach ((array) config('legal.cookies') as $cookie) {
            $this->assertContains(
                $cookie['category'],
                $known,
                "Cookie {$cookie['name']} is in category {$cookie['category']}, which the consent banner does not offer.",
            );

            foreach (['name', 'provider', 'purpose', 'retention'] as $field) {
                $this->assertNotEmpty(
                    $cookie[$field] ?? null,
                    "Cookie {$cookie['name']} has no {$field}, so the policy renders a blank cell.",
                );
            }
        }
    }

    /*
     * Consent is stored against this version and discarded when it moves, so a
     * page that fails to render it hands the browser no way to tell a current
     * answer from a stale one — and `consent.ts` fails closed, re-asking
     * everybody on every load.
     */
    #[Test]
    public function it_publishes_the_consent_version_to_the_browser(): void
    {
        $this->get('/cookies')
            ->assertOk()
            ->assertSee('name="consent-version"', false)
            ->assertSee((string) config('legal.consent_version'), false);
    }

    #[Test]
    #[DataProvider('documents')]
    public function no_document_sits_behind_a_login(string $path, string $component): void
    {
        $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));

        $this->assertNotContains(
            'auth',
            $route->gatherMiddleware(),
            "{$component} is behind the auth middleware, so nobody can read it before signing up.",
        );
    }

    #[Test]
    #[DataProvider('documents')]
    public function each_document_reads_the_same_whether_or_not_you_are_signed_in(string $path, string $component): void
    {
        $this->actingAs(User::factory()->create())
            ->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    /*
     * The legal pages are marketing-styled and always light; the product shell
     * paints its own palette and follows the theme. Rendering one inside the
     * other wraps a public document in signed-in chrome.
     */
    #[Test]
    #[DataProvider('documents')]
    public function no_document_renders_inside_the_product_shell(string $path, string $component): void
    {
        $this->get($path)
            ->assertInertia(fn (Assert $page) => $page->component($component))
            ->assertOk()
            ->assertDontSee('product-shell', false);
    }

    /*
     * The cookie inventory is published by PHP and enforced by TypeScript, and
     * nothing but these assertions connects the two. If `consent.ts` renames the
     * cookie it writes, the policy goes on naming the old one; if it starts
     * offering a category the policy does not describe, we are collecting
     * consent for something undisclosed. Both are silent in every other test.
     */
    #[Test]
    public function the_consent_store_writes_the_cookie_the_policy_publishes(): void
    {
        $source = (string) file_get_contents(resource_path('js/lib/consent.ts'));

        preg_match("/const COOKIE = '([^']+)'/", $source, $matches);
        $written = $matches[1] ?? '';

        $this->assertNotSame(
            '',
            $written,
            'Could not find the cookie name in resources/js/lib/consent.ts.',
        );

        $this->assertContains(
            $written,
            array_column((array) config('legal.cookies'), 'name'),
            "consent.ts writes a cookie named {$written} that the published policy never mentions.",
        );
    }

    #[Test]
    public function the_banner_offers_exactly_the_optional_categories_the_policy_describes(): void
    {
        $source = (string) file_get_contents(resource_path('js/lib/consent.ts'));

        preg_match('/OPTIONAL_CATEGORIES = \[([^\]]+)\]/', $source, $matches);
        $declared = $matches[1] ?? '';

        $this->assertNotSame(
            '',
            $declared,
            'Could not find OPTIONAL_CATEGORIES in resources/js/lib/consent.ts.',
        );

        preg_match_all("/'([a-z]+)'/", $declared, $found);

        $this->assertSame(
            ['analytics', 'marketing'],
            $found[1],
            'The banner offers a different set of choices than the cookie policy explains.',
        );
    }

    /*
     * The two preference cookies are published as always-on, which is only
     * defensible while they stay what the policy says they are. These assert the
     * names against the code that writes them, so renaming either cannot leave
     * the policy describing a cookie the browser never sees.
     */
    #[Test]
    public function the_published_preference_cookies_are_the_ones_the_interface_writes(): void
    {
        $published = array_column(
            array_filter(
                (array) config('legal.cookies'),
                static fn (array $cookie): bool => $cookie['category'] === 'preferences',
            ),
            'name'
        );

        $sidebar = (string) file_get_contents(resource_path('js/components/ui/sidebar.tsx'));
        $appearance = (string) file_get_contents(resource_path('js/hooks/use-appearance.tsx'));

        preg_match('/SIDEBAR_COOKIE_NAME = "([^"]+)"/', $sidebar, $matches);
        $sidebarCookie = $matches[1] ?? '';

        $this->assertNotSame(
            '',
            $sidebarCookie,
            'Could not find SIDEBAR_COOKIE_NAME in resources/js/components/ui/sidebar.tsx.',
        );
        $this->assertContains($sidebarCookie, $published);

        $this->assertStringContainsString(
            "setCookie('appearance'",
            $appearance,
            'The theme cookie is no longer called `appearance`, so the cookie policy names one that is never set.',
        );
        $this->assertContains('appearance', $published);
    }

    #[Test]
    public function the_privacy_policy_names_every_provider_that_receives_data(): void
    {
        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
            ->component('legal/privacy')
            ->has('subprocessors', count((array) config('legal.subprocessors')))
            ->has('subprocessors.0', fn (Assert $provider) => $provider
                ->has('name')
                ->has('purpose')
                ->has('region')
                ->has('optional'))
            ->etc());
    }
}
