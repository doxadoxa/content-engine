<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Enums\WebhookEvent;
use App\Models\BlogDeletion;
use App\Models\BlogDelivery;
use App\Models\BlogPost;
use App\Models\BlogSlugRedirect;
use App\Models\ContentItem;
use App\Models\Project;
use App\Publishing\WebhookPayload;
use App\Publishing\WebhookSignature;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Avyo's blog as a receiver of Avyo's own engine.
 *
 * The payloads are built by the engine's {@see WebhookPayload} and signed the
 * way WebhookPublisher signs them, so these tests prove that the two halves
 * of the contract fit rather than that the receiver agrees with itself.
 */
final class BlogWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'blog-channel-secret';

    private ContentItem $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://avyo.test');
        config()->set('blog.webhook_secret', $this->secret);
        config()->set('blog.locale', 'en');

        $project = Project::factory()->create(['website_url' => 'https://avyo.test']);
        app(CurrentProject::class)->set($project);

        $this->unit = ContentItem::factory()->published()->create([
            'title' => 'Why content engines forget their readers',
            'slug' => 'why-content-engines-forget-their-readers',
            'summary' => 'Most of them never read what they wrote.',
            // Shaped the way FinaliseDraft stores it: the writer's own headline
            // first, which is where the title was lifted from.
            'body_markdown' => "# Why content engines forget their readers\n\n"
                ."An opening paragraph. <script>alert('owned')</script>\n\n"
                ."## Where it goes wrong\n\n[Click](javascript:alert(1)) and read on.",
            'body_html' => '<h1>Why</h1><script>alert("from the sender")</script>',
            'json_ld' => ['@type' => 'Article'],
            'faq_json_ld' => ['@type' => 'FAQPage'],
            'author' => ['name' => 'Avyo'],
            'published_at' => Carbon::parse('2026-09-01T09:00:00Z'),
        ]);
    }

    // ------------------------------------------------------------ the door

    #[Test]
    public function an_unconfigured_blog_refuses_everything(): void
    {
        config()->set('blog.webhook_secret', null);

        $this->deliver($this->published())->assertStatus(503);

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function a_wrong_bearer_is_refused_even_with_a_valid_signature(): void
    {
        $this->deliver($this->published(), bearer: 'not-the-secret')->assertUnauthorized();

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function a_signature_from_another_secret_is_refused(): void
    {
        $this->deliver($this->published(), signingSecret: 'somebody-elses')->assertUnauthorized();

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function a_stale_timestamp_is_refused(): void
    {
        $this->deliver($this->published(), timestamp: time() - 3_600)->assertUnauthorized();

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function an_unsigned_request_is_refused_and_the_secret_is_never_echoed(): void
    {
        $response = $this->postJson('/blog/webhook', $this->published(), ['Authorization' => 'Bearer '.$this->secret])
            ->assertUnauthorized();

        $this->assertStringNotContainsString($this->secret, (string) $response->getContent());
    }

    #[Test]
    public function a_ping_is_answered_and_stores_nothing(): void
    {
        $this->deliver(WebhookPayload::ping($this->unit->project, WebhookPayload::newDeliveryId()))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertSame(0, BlogPost::query()->count());
    }

    // ----------------------------------------------------------- publishing

    #[Test]
    public function a_publication_is_stored_with_a_body_rendered_here_and_answers_with_an_absolute_url(): void
    {
        $payload = $this->published();

        $this->deliver($payload)
            ->assertOk()
            ->assertExactJson([
                'status' => 'stored',
                'public_url' => 'https://avyo.test/blog/why-content-engines-forget-their-readers',
            ]);

        $post = BlogPost::query()->sole();

        $this->assertSame($this->unit->getKey(), $post->engine_id);
        $this->assertSame($this->unit->locale_group_id, $post->locale_group_id);
        $this->assertSame('Why content engines forget their readers', $post->title);
        $this->assertSame('how_to', $post->type);
        $this->assertSame(['@type' => 'Article'], $post->json_ld);
        $this->assertSame(['name' => 'Avyo'], $post->author);
        $this->assertSame($payload['delivery_id'], $post->last_delivery_id);
        $this->assertTrue($post->published_at->equalTo(Carbon::parse('2026-09-01T09:00:00Z')));

        // Rendered from the Markdown, not taken from the sender's `html`.
        $this->assertStringNotContainsString('<script', $post->html);
        $this->assertStringNotContainsString('from the sender', $post->html);
        $this->assertStringNotContainsString('javascript:', $post->html);
        $this->assertStringContainsString('<h2>Where it goes wrong</h2>', $post->html);

        // The page renders the title as its h1; the body must not repeat it.
        $this->assertStringNotContainsString('<h1>', $post->html);
        $this->assertStringStartsWith('<p>An opening paragraph.', $post->html);
    }

    #[Test]
    public function an_opening_heading_that_is_not_the_title_is_kept(): void
    {
        $payload = $this->published();
        $payload['content']['markdown'] = "# Something else entirely\n\nBody.";

        $this->deliver($payload)->assertOk();

        $this->assertStringContainsString('<h1>Something else entirely</h1>', BlogPost::query()->sole()->html);
    }

    #[Test]
    public function an_update_replaces_the_same_row_and_keeps_its_date(): void
    {
        $this->deliver($this->published())->assertOk();
        $original = BlogPost::query()->sole();

        $this->travel(3)->days();

        $update = $this->payload(WebhookEvent::Updated);
        $update['content']['title'] = 'Why content engines forget, revised';
        $update['content']['published_at'] = null;

        $this->deliver($update)->assertOk()->assertJsonPath('status', 'stored');

        $post = BlogPost::query()->sole();

        $this->assertSame($original->getKey(), $post->getKey());
        $this->assertSame('Why content engines forget, revised', $post->title);
        $this->assertSame($update['delivery_id'], $post->last_delivery_id);
        // An update without a date is not a re-dating.
        $this->assertTrue($post->published_at->equalTo($original->published_at));
    }

    #[Test]
    public function a_publication_with_no_date_is_dated_now(): void
    {
        $this->freezeSecond();
        $payload = $this->published();
        $payload['content']['published_at'] = null;

        $this->deliver($payload)->assertOk();

        $this->assertTrue(BlogPost::query()->sole()->published_at->equalTo(now()));
    }

    #[Test]
    public function a_repeated_delivery_answers_409_and_writes_nothing(): void
    {
        $payload = $this->published();
        $this->deliver($payload)->assertOk();

        // Same delivery id, different bytes: still a repeat, and not an edit.
        $payload['content']['title'] = 'Changed in flight';

        // With the post's address: when this is a retry because the first
        // answer was lost, it is the only way the unit ever learns it.
        $this->deliver($payload)->assertStatus(409)->assertExactJson([
            'status' => 'duplicate',
            'public_url' => 'https://avyo.test/blog/why-content-engines-forget-their-readers',
        ]);

        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame(1, BlogDelivery::query()->count());
        $this->assertSame('Why content engines forget their readers', BlogPost::query()->sole()->title);
    }

    #[Test]
    public function a_slug_another_post_owns_is_refused_rather_than_taken(): void
    {
        $this->deliver($this->published())->assertOk();

        // The engine keeps slugs unique per project, so this is another
        // project's channel pointed here by mistake — or a unit whose deletion
        // never reached the blog.
        /** @var array<string, mixed> $payload */
        $payload = app(CurrentProject::class)->run(Project::factory()->create(), fn (): array => WebhookPayload::for(
            ContentItem::factory()->published()->create([
                'slug' => 'why-content-engines-forget-their-readers',
                'locale' => 'en',
                'body_markdown' => 'Another article.',
            ]),
            WebhookEvent::Published,
            WebhookPayload::newDeliveryId(),
        ));

        // Not 409: the engine would count that as delivered and the operator
        // would never hear the article went nowhere.
        $this->deliver($payload)->assertStatus(422)->assertJsonStructure(['error']);

        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame($this->unit->getKey(), BlogPost::query()->sole()->engine_id);
        $this->assertFalse(BlogDelivery::query()->where('delivery_id', $payload['delivery_id'])->exists());
    }

    #[Test]
    public function a_slug_in_the_articles_own_language_is_transliterated_to_fit_the_route(): void
    {
        $payload = $this->published();
        $payload['content']['locale'] = 'pt-PT';
        $payload['content']['slug'] = 'Limpeza de janelas: ção & só';

        $this->deliver($payload)
            ->assertOk()
            ->assertJsonPath('public_url', 'https://avyo.test/blog/pt-PT/limpeza-de-janelas-cao-so');

        $this->assertSame('limpeza-de-janelas-cao-so', BlogPost::query()->sole()->slug);
    }

    #[Test]
    public function a_missing_slug_falls_back_to_the_title(): void
    {
        $payload = $this->published();
        $payload['content']['slug'] = '';

        $this->deliver($payload)->assertOk();

        $this->assertSame('why-content-engines-forget-their-readers', BlogPost::query()->sole()->slug);
    }

    #[Test]
    public function a_title_with_no_transliteration_falls_back_to_the_units_id(): void
    {
        $payload = $this->published();
        $payload['content']['locale'] = 'zh-Hant-TW';
        $payload['content']['slug'] = '內容引擎為何忘記讀者';
        $payload['content']['title'] = '內容引擎為何忘記讀者';

        $id = strtolower((string) $this->unit->getKey());

        $this->deliver($payload)
            ->assertOk()
            ->assertJsonPath('public_url', "https://avyo.test/blog/zh-Hant-TW/{$id}");

        $this->assertSame($id, BlogPost::query()->sole()->slug);

        // And the address it answered with is one the blog serves.
        $this->get("/blog/zh-Hant-TW/{$id}")->assertOk()->assertSee('內容引擎為何忘記讀者');
    }

    #[Test]
    public function a_post_with_no_usable_slug_at_all_is_refused(): void
    {
        $payload = $this->published();
        $payload['content']['id'] = '!!!';
        $payload['content']['slug'] = '---';
        $payload['content']['title'] = '!!!';

        $this->deliver($payload)->assertStatus(422);

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function a_locale_the_route_cannot_serve_is_refused(): void
    {
        $payload = $this->published();
        $payload['content']['locale'] = 'en_GB';

        $this->deliver($payload)->assertStatus(422);

        $this->assertSame(0, BlogPost::query()->count());
    }

    #[Test]
    public function only_pictures_with_an_https_address_are_kept(): void
    {
        $payload = $this->published();
        $payload['content']['images'] = [
            ['role' => 'hero', 'url' => 'https://media.avyo.test/hero.webp', 'alt' => 'A hero', 'anchor' => null, 'width' => 1600, 'height' => 900, 'extra' => 'dropped'],
            ['role' => 'inline', 'url' => 'javascript:alert(1)', 'alt' => 'x'],
            // The CSP loads images over https only.
            ['role' => 'inline', 'url' => 'http://media.avyo.test/plain.webp', 'alt' => 'x'],
            'not-a-picture',
        ];

        $this->deliver($payload)->assertOk();

        $post = BlogPost::query()->sole();

        // assertEquals: jsonb keeps its own key order.
        $this->assertEquals([[
            'role' => 'hero', 'url' => 'https://media.avyo.test/hero.webp', 'alt' => 'A hero',
            'anchor' => null, 'width' => 1600, 'height' => 900,
        ]], $post->images);
        $this->assertSame('https://media.avyo.test/hero.webp', $post->hero()['url'] ?? null);
    }

    // -------------------------------------------------------------- deletes

    #[Test]
    public function a_deletion_removes_the_post_and_is_answered_the_same_when_already_gone(): void
    {
        $this->deliver($this->published())->assertOk();

        $this->deliver($this->payload(WebhookEvent::Deleted))->assertOk()->assertExactJson(['status' => 'deleted']);
        $this->assertSame(0, BlogPost::query()->count());

        $this->deliver($this->payload(WebhookEvent::Deleted))->assertOk()->assertExactJson(['status' => 'deleted']);
    }

    #[Test]
    public function a_repeated_deletion_carries_no_url(): void
    {
        $this->deliver($this->published())->assertOk();

        $delete = $this->payload(WebhookEvent::Deleted);
        $this->deliver($delete)->assertOk();

        $this->deliver($delete)->assertStatus(409)->assertExactJson(['status' => 'duplicate']);
    }

    // ------------------------------------------------------------ ordering

    #[Test]
    public function a_late_retry_of_an_older_version_does_not_overwrite_a_newer_one(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();

        $newer = $this->at($this->payload(WebhookEvent::Updated), '11:00');
        $newer['content']['title'] = 'The newer title';
        $this->deliver($newer)->assertOk()->assertJsonPath('status', 'stored');

        $before = BlogPost::query()->sole();
        $this->travel(1)->hour();

        // Queued at 10:30, and only getting through now.
        $older = $this->at($this->payload(WebhookEvent::Updated), '10:30');
        $older['content']['title'] = 'The older title';

        // 2xx, so the engine settles it and still records where the post is.
        $this->deliver($older)->assertOk()->assertExactJson([
            'status' => 'stale',
            'public_url' => 'https://avyo.test/blog/why-content-engines-forget-their-readers',
        ]);

        $post = BlogPost::query()->sole();
        $this->assertSame('The newer title', $post->title);
        $this->assertSame($newer['delivery_id'], $post->last_delivery_id);
        $this->assertTrue($post->updated_at->equalTo($before->updated_at));
    }

    #[Test]
    public function a_replay_is_stamped_anew_and_wins(): void
    {
        $original = $this->at($this->published(), '10:00');
        $original['content']['title'] = 'The replayed title';
        $this->deliver($original)->assertOk();

        $newer = $this->at($this->payload(WebhookEvent::Updated), '11:00');
        $newer['content']['title'] = 'The newer title';
        $this->deliver($newer)->assertOk();

        // What WebhookPublisher::replay() sends: the old bytes under a new
        // delivery id and a fresh sent_at. The operator asked for exactly this.
        $replay = $this->at($original, '12:00');
        $replay['delivery_id'] = WebhookPayload::newDeliveryId();

        $this->deliver($replay)->assertOk()->assertJsonPath('status', 'stored');

        $this->assertSame('The replayed title', BlogPost::query()->sole()->title);
    }

    #[Test]
    public function a_publish_older_than_the_deletion_leaves_the_post_deleted(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '12:00'))->assertOk();

        $this->deliver($this->at($this->published(), '11:00'))
            ->assertOk()
            // Nothing to point at: the post stays gone.
            ->assertExactJson(['status' => 'stale']);

        $this->assertSame(0, BlogPost::query()->count());
        $this->assertSame(1, BlogPost::withTrashed()->count());
        $this->get('/blog/why-content-engines-forget-their-readers')->assertNotFound();
    }

    #[Test]
    public function a_publish_newer_than_the_deletion_brings_the_post_back(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();
        $original = BlogPost::query()->sole();
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '12:00'))->assertOk();

        $this->deliver($this->at($this->published(), '13:00'))
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $post = BlogPost::query()->sole();
        $this->assertSame($original->getKey(), $post->getKey());
        $this->assertNull($post->deleted_at);
        $this->get('/blog/why-content-engines-forget-their-readers')->assertOk();
    }

    #[Test]
    public function a_deletion_that_overtakes_the_publish_keeps_the_late_publish_out(): void
    {
        // The publish was queued first but failed; the deletion reached the
        // blog before the publish's retry ever did.
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '12:00'))
            ->assertOk()
            ->assertJsonPath('status', 'deleted');

        $this->deliver($this->at($this->published(), '11:00'))
            ->assertOk()
            ->assertExactJson(['status' => 'stale']);

        $this->assertSame(0, BlogPost::withTrashed()->count());
        $this->get('/blog/why-content-engines-forget-their-readers')->assertNotFound();
    }

    #[Test]
    public function a_publish_newer_than_a_deletion_of_a_post_never_stored_publishes(): void
    {
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '12:00'))->assertOk();

        $this->deliver($this->at($this->published(), '13:00'))
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame(0, BlogDeletion::query()->count());
        $this->get('/blog/why-content-engines-forget-their-readers')->assertOk();
    }

    #[Test]
    public function a_late_retry_of_an_older_deletion_does_not_let_an_earlier_publish_through(): void
    {
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '12:00'))->assertOk();
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '10:00'))->assertOk();

        $this->deliver($this->at($this->published(), '11:00'))
            ->assertOk()
            ->assertExactJson(['status' => 'stale']);

        $this->assertSame(0, BlogPost::withTrashed()->count());
    }

    #[Test]
    public function a_deletion_older_than_the_live_version_removes_nothing(): void
    {
        $this->deliver($this->at($this->published(), '12:00'))->assertOk();

        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '11:00'))
            ->assertOk()
            ->assertExactJson(['status' => 'stale']);

        $this->assertSame(1, BlogPost::query()->count());
    }

    #[Test]
    public function a_delivery_without_a_readable_sent_at_is_applied(): void
    {
        $this->deliver($this->at($this->published(), '12:00'))->assertOk();

        $undated = $this->payload(WebhookEvent::Updated);
        $undated['sent_at'] = 'not a date';
        $undated['content']['title'] = 'Undated, but applied';

        $this->deliver($undated)->assertOk()->assertJsonPath('status', 'stored');

        $post = BlogPost::query()->sole();
        $this->assertSame('Undated, but applied', $post->title);
        // The last stamp that could be read still orders what comes next.
        $this->assertTrue($post->source_sent_at?->equalTo(Carbon::parse('2026-09-10T12:00:00Z')));
    }

    // ------------------------------------------------------ what "changed" is

    #[Test]
    public function a_delivery_that_changes_nothing_does_not_move_updated_at(): void
    {
        $payload = $this->at($this->published(), '10:00');
        // Keys in the contract's order, which jsonb does not keep.
        $payload['content']['images'] = [
            ['role' => 'hero', 'url' => 'https://media.avyo.test/hero.webp', 'alt' => 'A hero', 'anchor' => null, 'width' => 1600, 'height' => 900],
        ];
        $this->deliver($payload)->assertOk();
        $stored = BlogPost::query()->sole();

        $this->travel(1)->day();

        $again = $payload;
        $again['event'] = WebhookEvent::Updated->value;
        $again['delivery_id'] = WebhookPayload::newDeliveryId();
        $again['sent_at'] = Carbon::parse('2026-09-10T11:00:00Z')->toIso8601String();
        $this->deliver($again)->assertOk()->assertJsonPath('status', 'stored');

        $post = BlogPost::query()->sole();
        $this->assertTrue($post->updated_at->equalTo($stored->updated_at), 'A repeat is not a modification.');
        // It is still recorded as the last thing that arrived.
        $this->assertSame($again['delivery_id'], $post->last_delivery_id);
        $this->assertTrue($post->source_sent_at?->equalTo(Carbon::parse('2026-09-10T11:00:00Z')));

        $this->travel(1)->day();

        $edit = $again;
        $edit['delivery_id'] = WebhookPayload::newDeliveryId();
        $edit['sent_at'] = Carbon::parse('2026-09-10T12:00:00Z')->toIso8601String();
        $edit['content']['summary'] = 'A better summary.';
        $this->deliver($edit)->assertOk();

        $this->assertTrue(BlogPost::query()->sole()->updated_at->greaterThan($stored->updated_at));
    }

    // ---------------------------------------------------------------- slugs

    #[Test]
    public function a_renamed_slug_leaves_a_permanent_redirect_behind(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();

        $rename = $this->at($this->payload(WebhookEvent::Updated), '11:00');
        $rename['content']['slug'] = 'content-engines-and-their-readers';

        $this->deliver($rename)
            ->assertOk()
            ->assertJsonPath('public_url', 'https://avyo.test/blog/content-engines-and-their-readers');

        $this->get('/blog/why-content-engines-forget-their-readers')
            ->assertStatus(301)
            ->assertHeader('Location', 'https://avyo.test/blog/content-engines-and-their-readers');
        $this->get('/blog/content-engines-and-their-readers')->assertOk();
    }

    #[Test]
    public function a_new_post_takes_a_slug_that_only_redirects(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();

        $rename = $this->at($this->payload(WebhookEvent::Updated), '11:00');
        $rename['content']['slug'] = 'content-engines-and-their-readers';
        $this->deliver($rename)->assertOk();

        $other = $this->payloadFor(ContentItem::factory()->published()->create([
            'title' => 'A different article',
            'slug' => 'a-different-article',
            'body_markdown' => 'Another article.',
        ]));
        // The engine let the address go when the first unit moved off it.
        $other['content']['slug'] = 'why-content-engines-forget-their-readers';

        $this->deliver($other)->assertOk()->assertJsonPath('status', 'stored');

        // A post that really has the address beats a pointer to where one was.
        $this->get('/blog/why-content-engines-forget-their-readers')->assertOk()->assertSee('A different article');
        $this->assertSame(0, BlogSlugRedirect::query()->count());
    }

    #[Test]
    public function a_deleted_posts_slug_goes_to_the_next_unit_that_needs_it(): void
    {
        $this->deliver($this->at($this->published(), '10:00'))->assertOk();
        $this->deliver($this->at($this->payload(WebhookEvent::Deleted), '11:00'))->assertOk();

        $other = $this->payloadFor(ContentItem::factory()->published()->create([
            'title' => 'A different article',
            'slug' => 'a-different-article',
            'body_markdown' => 'Another article.',
        ]));
        // The engine let the address go when the first unit moved off it.
        $other['content']['slug'] = 'why-content-engines-forget-their-readers';

        $this->deliver($other)->assertOk()->assertJsonPath('status', 'stored');

        // The tombstone owned no URL any more; it goes for good.
        $this->assertSame(1, BlogPost::withTrashed()->count());
        $this->assertNotSame($this->unit->getKey(), BlogPost::query()->sole()->engine_id);
    }

    // ------------------------------------------------------ what is refused

    #[Test]
    public function an_article_without_a_body_is_refused_and_leaves_nothing_behind(): void
    {
        $payload = $this->published();
        $payload['content']['markdown'] = "  \n ";

        $this->deliver($payload)->assertStatus(422)->assertJsonStructure(['error']);

        $this->assertSame(0, BlogPost::query()->count());
        $this->assertSame(0, BlogDelivery::query()->count());
    }

    #[Test]
    public function an_envelope_the_contract_does_not_describe_is_refused(): void
    {
        $noId = $this->published();
        $noId['delivery_id'] = 'not-a-uuid';
        $this->deliver($noId)->assertStatus(422);

        $unknown = $this->published();
        $unknown['event'] = 'content.exploded';
        $this->deliver($unknown)->assertStatus(422);

        $noContent = $this->published();
        unset($noContent['content']);
        $this->deliver($noContent)->assertStatus(422);

        $this->assertSame(0, BlogPost::query()->count());
        $this->assertSame(0, BlogDelivery::query()->count());
    }

    #[Test]
    public function a_contract_version_this_receiver_does_not_know_is_refused(): void
    {
        $later = $this->published();
        $later['contract'] = 2;
        $this->deliver($later)->assertStatus(422)->assertJsonStructure(['error']);

        $none = $this->published();
        unset($none['contract']);
        $this->deliver($none)->assertStatus(422);

        $this->assertSame(0, BlogPost::query()->count());
        $this->assertSame(0, BlogDelivery::query()->count());
    }

    /*
     * The suite skips CSRF checks, so the exemption in bootstrap/app.php
     * would go unnoticed if it were dropped. This middleware checks for real.
     */
    #[Test]
    public function the_webhook_is_exempt_from_csrf_and_nothing_else_is(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        // The check is live: a form post without a token is refused…
        $this->post('/login', ['email' => 'someone@example.test', 'password' => 'secret'])->assertStatus(419);

        // …and the engine, which has no session to hold one, is not.
        $this->deliver($this->published())->assertOk()->assertJsonPath('status', 'stored');
    }

    #[Test]
    public function a_write_that_fails_does_not_spend_the_delivery_id(): void
    {
        // After the claim, in the middle of the write.
        BlogPost::saving(static function (): never {
            throw new RuntimeException('The database went away mid-write.');
        });

        $payload = $this->published();

        // The engine retries a 5xx with the same delivery id…
        $this->deliver($payload)->assertStatus(500);
        $this->assertSame(0, BlogDelivery::query()->count());
        $this->assertSame(0, BlogPost::query()->count());

        BlogPost::flushEventListeners();

        // …and that retry must be stored, not answered 409 as if it had been.
        $this->deliver($payload)->assertOk()->assertJsonPath('status', 'stored');
        $this->assertSame(1, BlogPost::query()->count());
    }

    /**
     * Signed exactly as WebhookPublisher::sendRequest signs it.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function deliver(
        array $payload,
        ?string $signingSecret = null,
        ?string $bearer = null,
        ?int $timestamp = null,
    ): TestResponse {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp ??= time();

        return $this->call(
            'POST',
            '/blog/webhook',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.($bearer ?? $this->secret),
                'HTTP_X_ENGINE_DELIVERY' => (string) ($payload['delivery_id'] ?? ''),
                'HTTP_X_ENGINE_EVENT' => (string) ($payload['event'] ?? ''),
                'HTTP_X_ENGINE_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ENGINE_SIGNATURE' => WebhookSignature::compute($signingSecret ?? $this->secret, $timestamp, $body),
                'HTTP_X_ENGINE_CONTRACT' => '1',
                'CONTENT_TYPE' => 'application/json; charset=utf-8',
            ],
            content: $body,
        );
    }

    /**
     * The payload as if queued at `$time` on 10 September — the envelope's
     * `sent_at`, which is what orders deliveries for the same post.
     *
     * @template T of array<string, mixed>
     *
     * @param  T  $payload
     * @return T
     */
    private function at(array $payload, string $time): array
    {
        $payload['sent_at'] = Carbon::parse("2026-09-10T{$time}:00Z")->toIso8601String();

        return $payload;
    }

    /** @return array{contract: int, delivery_id: string, event: string, content: array<string, mixed>}&array<string, mixed> */
    private function payloadFor(ContentItem $unit): array
    {
        /** @var array{contract: int, delivery_id: string, event: string, content: array<string, mixed>}&array<string, mixed> $payload */
        $payload = WebhookPayload::for($unit, WebhookEvent::Published, WebhookPayload::newDeliveryId());

        return $payload;
    }

    /** @return array{contract: int, delivery_id: string, event: string, content: array<string, mixed>}&array<string, mixed> */
    private function published(): array
    {
        return $this->payload(WebhookEvent::Published);
    }

    /** @return array{contract: int, delivery_id: string, event: string, content: array<string, mixed>}&array<string, mixed> */
    private function payload(WebhookEvent $event): array
    {
        /** @var array{contract: int, delivery_id: string, event: string, content: array<string, mixed>}&array<string, mixed> $payload */
        $payload = WebhookPayload::for($this->unit, $event, WebhookPayload::newDeliveryId());

        return $payload;
    }
}
