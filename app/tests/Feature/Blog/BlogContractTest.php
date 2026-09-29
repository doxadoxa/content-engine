<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\BlogPost;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The engine and the blog, end to end, with nothing hand-built in between.
 *
 * The engine queues a delivery the way it would for any customer, and the
 * outgoing request — headers, signature, body and all — is handed to the
 * blog's own route. What the blog answers goes back to the engine, which
 * settles the delivery and records the URL as it would from anyone. If either
 * half drifts from the contract, this is where it shows.
 */
final class BlogContractTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'blog-channel-secret';

    private Channel $channel;

    private ContentItem $unit;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL is the project's website: the engine keeps a public_url
        // only on the project's own origin, and the blog answers on APP_URL.
        config()->set('app.url', 'https://avyo.test');
        config()->set('blog.webhook_secret', self::SECRET);
        config()->set('blog.locale', 'en');
        config()->set('queue.default', 'sync');

        $project = Project::factory()->create(['website_url' => 'https://avyo.test']);
        app(CurrentProject::class)->set($project);

        $this->channel = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'config' => ['endpoint' => 'https://avyo.test/blog/webhook'],
            'secret' => self::SECRET,
        ]);

        $this->unit = ContentItem::factory()->published()->create([
            'title' => 'Why content engines forget their readers',
            'slug' => 'why-content-engines-forget-their-readers',
            'summary' => 'Most of them never read what they wrote.',
            'body_markdown' => "# Why content engines forget their readers\n\nAn opening paragraph.",
            'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Article'],
            'author' => ['name' => 'Avyo'],
            'published_at' => Carbon::parse('2026-09-01T09:00:00Z'),
        ]);
    }

    #[Test]
    public function a_delivery_the_engine_queues_lands_on_the_blog_and_its_url_on_the_unit(): void
    {
        Http::fake(['avyo.test/blog/webhook' => fn (Request $request): PromiseInterface => $this->forward($request)]);

        $delivery = app(WebhookPublisher::class)->queue($this->unit, $this->channel);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status, (string) $delivery->error);

        $post = BlogPost::query()->sole();
        $this->assertSame($this->unit->getKey(), $post->engine_id);
        $this->assertSame('Why content engines forget their readers', $post->title);
        $this->assertSame('https://avyo.test/blog/why-content-engines-forget-their-readers', $post->publicUrl());
        $this->assertSame($post->publicUrl(), $this->unit->refresh()->public_url);

        $this->get('/blog/why-content-engines-forget-their-readers')->assertOk();
    }

    /*
     * The blog stored the post, but its answer never reached the engine. The
     * engine retries the same delivery id, the blog says 409 — and the unit
     * still has to come away with its URL.
     */
    #[Test]
    public function a_lost_answer_still_leaves_the_unit_with_its_url(): void
    {
        $calls = 0;

        Http::fake(['avyo.test/blog/webhook' => function (Request $request) use (&$calls): PromiseInterface {
            $answer = $this->forward($request);

            return ++$calls === 1 ? Http::response('upstream timed out', 504) : $answer;
        }]);

        $delivery = app(WebhookPublisher::class)->queue($this->unit, $this->channel);

        $this->assertSame(2, $calls);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->refresh()->status, (string) $delivery->error);
        $this->assertSame(1, BlogPost::query()->count());
        $this->assertSame(
            'https://avyo.test/blog/why-content-engines-forget-their-readers',
            $this->unit->refresh()->public_url,
        );
    }

    /**
     * The engine's outgoing request, replayed into the application as the
     * HTTP request the blog would receive, and the blog's answer handed back.
     */
    private function forward(Request $request): PromiseInterface
    {
        $server = [];

        foreach ($request->headers() as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = implode(', ', $values);
        }

        $response = $this->call('POST', '/blog/webhook', server: $server, content: $request->body());

        return Http::response(
            (string) $response->getContent(),
            $response->getStatusCode(),
            ['Content-Type' => 'application/json'],
        );
    }
}
