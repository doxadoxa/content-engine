<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Models\BlogPost;
use App\Models\BlogSlugRedirect;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SimpleXMLElement;
use Tests\TestCase;

/*
 * What readers and crawlers get from the blog.
 *
 * Most of these read the raw HTML the server sent rather than anything a
 * browser would build from it, because that is exactly what a crawler that
 * does not run JavaScript sees — and the reason the blog is Blade at all.
 */
final class BlogPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://avyo.test', 'blog.locale' => 'en', 'blog.per_page' => 12]);
    }

    #[Test]
    public function the_index_lists_live_posts_newest_first_and_hides_scheduled_ones(): void
    {
        BlogPost::factory()->create(['title' => 'Older post', 'published_at' => now()->subDays(5)]);
        BlogPost::factory()->create(['title' => 'Newer post', 'published_at' => now()->subDay()]);
        BlogPost::factory()->scheduled()->create(['title' => 'Tomorrow’s post']);

        $this->get('/blog')
            ->assertOk()
            ->assertSeeInOrder(['Newer post', 'Older post'])
            ->assertDontSee('Tomorrow’s post')
            ->assertSee('<link rel="canonical" href="https://avyo.test/blog">', false)
            ->assertSee('<title>Blog — Avyo</title>', false)
            ->assertSee('rel="alternate" type="application/rss+xml"', false);
    }

    #[Test]
    public function the_index_lists_only_the_default_language(): void
    {
        $post = BlogPost::factory()->create(['title' => 'In English']);
        BlogPost::factory()->translationOf($post, 'de')->create(['title' => 'Auf Deutsch']);

        $this->get('/blog')->assertOk()->assertSee('In English')->assertDontSee('Auf Deutsch');
    }

    #[Test]
    public function an_empty_blog_says_so_rather_than_rendering_a_blank_grid(): void
    {
        $this->get('/blog')
            ->assertOk()
            ->assertSee('Nothing published yet')
            ->assertDontSee('rel="next"', false);
    }

    #[Test]
    public function the_index_paginates_with_canonical_and_prev_next_links(): void
    {
        config(['blog.per_page' => 2]);

        foreach (range(1, 5) as $day) {
            BlogPost::factory()->create(['title' => "Post {$day}", 'published_at' => now()->subDays($day)]);
        }

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Post 1')
            ->assertDontSee('Post 3')
            ->assertSee('<link rel="next" href="https://avyo.test/blog?page=2">', false)
            ->assertDontSee('rel="prev"', false);

        $this->get('/blog?page=2')
            ->assertOk()
            ->assertSeeInOrder(['Post 3', 'Post 4'])
            ->assertSee('<link rel="canonical" href="https://avyo.test/blog?page=2">', false)
            ->assertSee('<link rel="prev" href="https://avyo.test/blog">', false)
            ->assertSee('<link rel="next" href="https://avyo.test/blog?page=3">', false);

        $this->get('/blog?page=3')->assertOk()->assertSee('Post 5')->assertDontSee('rel="next"', false);
    }

    #[Test]
    public function a_page_past_the_end_is_a_404_not_an_empty_page(): void
    {
        BlogPost::factory()->create();

        $this->get('/blog?page=9')->assertNotFound();
    }

    #[Test]
    public function a_post_is_rendered_in_full_in_the_server_html(): void
    {
        BlogPost::factory()->withHero()->create([
            'slug' => 'how-to-be-found',
            'title' => 'How to be found',
            'summary' => 'A short guide to being found.',
            'html' => '<p>The body of the article.</p><h2>A section</h2>',
            'author' => ['name' => 'Ada Writer', 'title' => 'Editor'],
            'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'How to be found'],
            'faq_json_ld' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []],
        ]);

        $response = $this->get('/blog/how-to-be-found')
            ->assertOk()
            ->assertSee('<title>How to be found — Avyo</title>', false)
            ->assertSee('<meta name="description" content="A short guide to being found.">', false)
            ->assertSee('<link rel="canonical" href="https://avyo.test/blog/how-to-be-found">', false)
            ->assertSee('<p>The body of the article.</p><h2>A section</h2>', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:image" content="https://media.example.com/hero.jpg">', false)
            ->assertSee('property="article:published_time"', false)
            ->assertSee('alt="A hand-drawn map of a small town"', false)
            ->assertSee('width="1600" height="900"', false)
            ->assertSee('Ada Writer');

        $html = $this->document((string) $response->getContent());
        $this->assertSame(1, $html->getElementsByTagName('h1')->length, 'A post page has exactly one h1.');
        $this->assertSame(1, $html->getElementsByTagName('time')->length);

        $blocks = $this->jsonLd((string) $response->getContent());
        $this->assertCount(2, $blocks);

        // The engine's schema wins where it speaks; the receiver adds where
        // the article lives and when it went up.
        $this->assertSame('Article', $blocks[0]['@type']);
        $this->assertSame('https://avyo.test/blog/how-to-be-found', $blocks[0]['url']);
        $this->assertArrayHasKey('datePublished', $blocks[0]);
        $this->assertSame('https://media.example.com/hero.jpg', $blocks[0]['image']);
        $this->assertSame('FAQPage', $blocks[1]['@type']);
    }

    #[Test]
    public function a_post_without_structured_data_gets_a_blog_posting(): void
    {
        BlogPost::factory()->create(['slug' => 'plain', 'title' => 'Plain', 'json_ld' => null]);

        $blocks = $this->jsonLd((string) $this->get('/blog/plain')->assertOk()->getContent());

        $this->assertCount(1, $blocks);
        $this->assertSame('BlogPosting', $blocks[0]['@type']);
        $this->assertSame('Plain', $blocks[0]['headline']);
        $this->assertSame('Avyo', $blocks[0]['publisher']['name']);
    }

    #[Test]
    public function a_post_without_a_hero_falls_back_to_the_site_image(): void
    {
        BlogPost::factory()->create(['slug' => 'no-hero']);

        $this->get('/blog/no-hero')
            ->assertOk()
            // From APP_URL, not whichever host the request came in on.
            ->assertSee('<meta property="og:image" content="https://avyo.test/og.png">', false);

        $this->get('/blog')->assertOk()->assertSee('<meta property="og:image" content="https://avyo.test/og.png">', false);
    }

    #[Test]
    public function engine_structured_data_that_is_not_an_article_stands_beside_the_blog_posting(): void
    {
        $itemList = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'headline' => 'Ten ways to be found'];

        BlogPost::factory()->create(['slug' => 'ten-ways', 'title' => 'Ten ways to be found', 'json_ld' => $itemList]);

        $blocks = $this->jsonLd((string) $this->get('/blog/ten-ways')->assertOk()->getContent());

        // Ours whole, the engine's untouched: an ItemList with a publisher and
        // a datePublished is not what an ItemList is.
        $this->assertCount(2, $blocks);
        $this->assertSame('BlogPosting', $blocks[0]['@type']);
        $this->assertSame('https://avyo.test/blog/ten-ways', $blocks[0]['url']);
        $this->assertEquals($itemList, $blocks[1]);
    }

    #[Test]
    public function engine_structured_data_of_an_article_type_is_filled_in(): void
    {
        BlogPost::factory()->create([
            'slug' => 'how-to-be-found',
            'json_ld' => ['@context' => 'https://schema.org', '@type' => 'HowTo', 'headline' => 'How to be found'],
        ]);

        $blocks = $this->jsonLd((string) $this->get('/blog/how-to-be-found')->assertOk()->getContent());

        $this->assertCount(1, $blocks);
        $this->assertSame('HowTo', $blocks[0]['@type']);
        $this->assertSame('https://avyo.test/blog/how-to-be-found', $blocks[0]['url']);
        $this->assertArrayHasKey('datePublished', $blocks[0]);
    }

    #[Test]
    public function a_deleted_post_is_gone_from_every_page(): void
    {
        $english = BlogPost::factory()->create(['slug' => 'being-found', 'title' => 'Still here']);
        $german = BlogPost::factory()->translationOf($english, 'de')->create(['slug' => 'gefunden-werden']);
        BlogPost::factory()->create(['slug' => 'gone', 'title' => 'Deleted article'])->delete();
        $german->delete();

        $this->get('/blog/gone')->assertNotFound();
        $this->get('/blog/de/gefunden-werden')->assertNotFound();
        $this->get('/blog')->assertOk()->assertSee('Still here')->assertDontSee('Deleted article');

        // A deleted translation is not advertised as an alternate either.
        $this->get('/blog/being-found')->assertOk()->assertDontSee('hreflang=', false);

        $feed = (string) $this->get('/blog/feed.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('Deleted article', $feed);
        $this->assertStringNotContainsString('/blog/gone', $feed);

        $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/blog/gone', $sitemap);
        $this->assertStringNotContainsString('gefunden-werden', $sitemap);
    }

    #[Test]
    public function a_former_slug_redirects_to_the_posts_address_while_it_is_live(): void
    {
        $post = BlogPost::factory()->create(['slug' => 'the-new-address']);
        BlogSlugRedirect::query()->create(['locale' => 'en', 'slug' => 'the-old-address', 'blog_post_id' => $post->getKey()]);

        $german = BlogPost::factory()->translationOf($post, 'de')->create(['slug' => 'neue-adresse']);
        BlogSlugRedirect::query()->create(['locale' => 'de', 'slug' => 'alte-adresse', 'blog_post_id' => $german->getKey()]);

        // Absolute on APP_URL, whatever host the request came in on.
        $this->get('/blog/the-old-address')->assertStatus(301)->assertHeader('Location', 'https://avyo.test/blog/the-new-address');
        $this->get('/blog/de/alte-adresse')->assertStatus(301)->assertHeader('Location', 'https://avyo.test/blog/de/neue-adresse');

        // The default language's long form of a former slug: one hop, not two.
        $this->get('/blog/en/the-old-address')->assertStatus(301)->assertHeader('Location', 'https://avyo.test/blog/the-new-address');

        // Never a redirect into a 404.
        $post->update(['published_at' => now()->addDay()]);
        $this->get('/blog/the-old-address')->assertNotFound();

        $german->delete();
        $this->get('/blog/de/alte-adresse')->assertNotFound();
    }

    #[Test]
    public function a_locale_with_several_subtags_is_routed(): void
    {
        BlogPost::factory()->create(['slug' => 'zh-post', 'locale' => 'zh-Hant-TW', 'title' => 'In Traditional Chinese']);

        $this->get('/blog/zh-Hant-TW/zh-post')->assertOk()->assertSee('<html lang="zh-Hant-TW"', false);
    }

    #[Test]
    public function characters_xml_cannot_hold_are_dropped_from_the_feed(): void
    {
        BlogPost::factory()->create([
            'slug' => 'control',
            'title' => "Bell\u{0007} and escape\u{001B}",
            'summary' => "Vertical\u{000B}tab",
            'html' => "<p>Form\u{000C}feed</p>",
        ]);

        $feed = new SimpleXMLElement((string) $this->get('/blog/feed.xml')->assertOk()->getContent());

        $this->assertSame('Bell and escape', (string) $feed->channel->item[0]->title);
        $this->assertSame('Verticaltab', (string) $feed->channel->item[0]->description);
        $this->assertSame('<p>Formfeed</p>', (string) $feed->channel->item[0]->children('content', true)->encoded);
    }

    /*
     * The one place engine-written text lands inside a <script>. If `</script>`
     * could close it, whatever followed would be parsed as markup — a stored
     * XSS written by our own content pipeline.
     */
    #[Test]
    public function structured_data_cannot_break_out_of_its_script_element(): void
    {
        $payload = '</script><script>alert(1)</script>';

        BlogPost::factory()->create([
            'slug' => 'hostile',
            'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $payload],
            'faq_json_ld' => ['@type' => 'FAQPage', 'name' => $payload],
        ]);

        $response = $this->get('/blog/hostile')->assertOk();

        $this->assertStringNotContainsString($payload, (string) $response->getContent());
        $this->assertStringNotContainsString('<script>alert(1)', (string) $response->getContent());

        // And it still decodes to exactly what the engine sent.
        $blocks = $this->jsonLd((string) $response->getContent());
        $this->assertSame($payload, $blocks[0]['headline']);
        $this->assertSame($payload, $blocks[1]['name']);
    }

    #[Test]
    public function a_scheduled_post_is_not_found_until_its_date(): void
    {
        BlogPost::factory()->scheduled()->create(['slug' => 'coming-soon']);

        $this->get('/blog/coming-soon')->assertNotFound();
    }

    #[Test]
    public function an_unknown_slug_is_not_found(): void
    {
        $this->get('/blog/nothing-here')->assertNotFound();
    }

    #[Test]
    public function a_post_in_another_language_lives_under_its_locale(): void
    {
        $english = BlogPost::factory()->create(['slug' => 'being-found', 'title' => 'Being found']);
        BlogPost::factory()->translationOf($english, 'de')->create(['slug' => 'gefunden-werden', 'title' => 'Gefunden werden']);

        $this->get('/blog/de/gefunden-werden')
            ->assertOk()
            ->assertSee('<html lang="de"', false)
            ->assertSee('Gefunden werden')
            ->assertSee('<link rel="canonical" href="https://avyo.test/blog/de/gefunden-werden">', false);

        // Not at the default language's short address.
        $this->get('/blog/gefunden-werden')->assertNotFound();
        $this->get('/blog/fr/gefunden-werden')->assertNotFound();
    }

    #[Test]
    public function the_default_language_has_one_address_and_its_long_form_redirects_there(): void
    {
        BlogPost::factory()->create(['slug' => 'being-found']);

        $this->get('/blog/en/being-found')->assertStatus(301)->assertHeader('Location', 'https://avyo.test/blog/being-found');
        $this->get('/blog/en/nothing-here')->assertNotFound();
    }

    #[Test]
    public function every_language_of_an_article_names_the_others(): void
    {
        $english = BlogPost::factory()->create(['slug' => 'being-found']);
        BlogPost::factory()->translationOf($english, 'de')->create(['slug' => 'gefunden-werden']);
        BlogPost::factory()->translationOf($english, 'fr')->scheduled()->create(['slug' => 'etre-trouve']);

        foreach (['/blog/being-found', '/blog/de/gefunden-werden'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('<link rel="alternate" hreflang="en" href="https://avyo.test/blog/being-found">', false)
                ->assertSee('<link rel="alternate" hreflang="de" href="https://avyo.test/blog/de/gefunden-werden">', false)
                ->assertSee('<link rel="alternate" hreflang="x-default" href="https://avyo.test/blog/being-found">', false)
                // A translation that is not out yet is not advertised.
                ->assertDontSee('etre-trouve');
        }

        $this->get('/blog/being-found')->assertSee('hreflang="de" lang="de"', false)->assertSee('Deutsch');
    }

    #[Test]
    public function a_single_language_article_carries_no_hreflang(): void
    {
        BlogPost::factory()->create(['slug' => 'only-english']);

        $this->get('/blog/only-english')->assertOk()->assertDontSee('hreflang=', false);
    }

    #[Test]
    public function the_feed_is_rss_of_live_posts_in_the_default_language(): void
    {
        BlogPost::factory()->create(['slug' => 'first', 'title' => 'First & foremost', 'published_at' => now()->subDays(2)]);
        BlogPost::factory()->create(['slug' => 'second', 'title' => 'Second', 'published_at' => now()->subDay()]);
        BlogPost::factory()->scheduled()->create(['slug' => 'later']);
        BlogPost::factory()->create(['slug' => 'zweite', 'locale' => 'de']);

        $response = $this->get('/blog/feed.xml')->assertOk();

        $this->assertStringStartsWith('application/rss+xml', (string) $response->headers->get('Content-Type'));

        $feed = new SimpleXMLElement((string) $response->getContent());
        $items = $feed->channel->item;

        $this->assertCount(2, $items);
        $this->assertSame('Second', (string) $items[0]->title);
        $this->assertSame('First & foremost', (string) $items[1]->title);
        $this->assertSame('https://avyo.test/blog/first', (string) $items[1]->link);
        $this->assertStringContainsString('<p>', (string) $items[1]->children('content', true)->encoded);
    }

    #[Test]
    public function the_sitemap_lists_the_public_site_and_every_live_post(): void
    {
        $english = BlogPost::factory()->create(['slug' => 'being-found']);
        BlogPost::factory()->translationOf($english, 'de')->create(['slug' => 'gefunden-werden']);
        BlogPost::factory()->scheduled()->create(['slug' => 'not-yet']);

        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));

        $sitemap = new SimpleXMLElement((string) $response->getContent());
        $sitemap->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $sitemap->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

        $locs = array_map('strval', $sitemap->xpath('//s:url/s:loc') ?: []);

        foreach (['/', '/blog', '/terms', '/privacy', '/cookies', '/blog/being-found', '/blog/de/gefunden-werden'] as $path) {
            $this->assertContains("https://avyo.test{$path}", $locs);
        }

        $this->assertNotContains('https://avyo.test/blog/not-yet', $locs);

        $alternates = $sitemap->xpath('//s:url[s:loc="https://avyo.test/blog/being-found"]/xhtml:link') ?: [];
        $this->assertSame(
            ['de', 'en', 'x-default'],
            array_map(static fn (SimpleXMLElement $link): string => (string) $link['hreflang'], $alternates),
        );
    }

    #[Test]
    public function the_blog_is_plain_html_with_no_react_bundle(): void
    {
        BlogPost::factory()->create(['slug' => 'static']);

        foreach (['/blog', '/blog/static'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('data-page=', false)
                ->assertDontSee('app.tsx', false)
                ->assertDontSee('product-shell', false);
        }
    }

    #[Test]
    public function the_landing_page_still_renders_and_links_to_the_blog(): void
    {
        $this->get('/')->assertOk();

        $this->assertStringContainsString(
            'href={blog.url()}',
            (string) file_get_contents(resource_path('js/pages/marketing.tsx')),
        );
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return $document;
    }

    /**
     * Every JSON-LD block on the page, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLd(string $html): array
    {
        $blocks = [];

        foreach ($this->document($html)->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') === 'application/ld+json') {
                $blocks[] = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $blocks;
    }
}
