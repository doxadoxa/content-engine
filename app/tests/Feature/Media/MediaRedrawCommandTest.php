<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Content\ArticleBusinessFacts;
use App\Enums\AssetRole;
use App\Enums\WebhookEvent;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Media\GeneratedImage;
use App\Media\MediaWriteFailed;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pictures written before the project had a bucket.
 *
 * On 2026-09-28 production had `MEDIA_DISK` unset, so every generated picture
 * was written to the worker container's own disk and recorded as `public`. The
 * web container could not see them and the next deploy removed them. Setting
 * the variable fixed the next picture and none of the existing ones: the rows
 * still said `public`, and a rewrite reused them.
 */
final class MediaRedrawCommandTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeImageGeneration $images;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');
        config(['media.disk' => 's3']);
        Queue::fake();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        /** @var FakeImageGeneration $images */
        $images = app(ImageGenerationProvider::class);
        $this->images = $images;
    }

    #[Test]
    public function a_file_still_on_its_old_disk_is_copied_rather_than_paid_for_again(): void
    {
        $unit = $this->article('How to clean grout');
        $inline = $this->picture($unit, AssetRole::Inline, 'Tools you need', onDisk: true);

        $this->redraw()->assertSuccessful();

        $this->assertSame([], $this->images->prompts());
        $inline->refresh();
        $this->assertSame('s3', $inline->disk);
        Storage::disk('s3')->assertExists($inline->path);
        $this->assertStringContainsString(
            '![Tools you need]('.Storage::disk('s3')->url($inline->path).')',
            (string) $unit->refresh()->body_markdown,
        );
    }

    #[Test]
    public function a_missing_file_shared_by_two_locales_is_drawn_once_and_both_follow(): void
    {
        $portuguese = $this->article('Limpeza pós-obra');
        $english = $portuguese->addLocale('en-GB', $portuguese->slug.'-en', 'Post-renovation cleaning');
        $english->forceFill(['state' => $portuguese->state])->save();

        $path = 'generated/'.Str::random(24).'.webp';
        $original = $this->picture($portuguese, AssetRole::Hero, $portuguese->title, path: $path);
        $borrowed = $this->picture($english, AssetRole::Hero, $english->title, path: $path);

        $this->redraw()->assertSuccessful();

        $this->assertCount(1, $this->images->prompts(), 'One file, one generation.');
        $this->assertStringContainsString('Limpeza pós-obra', $this->images->prompts()[0]);

        $original->refresh();
        $borrowed->refresh();
        $this->assertSame('s3', $original->disk);
        $this->assertSame($original->path, $borrowed->path);
        $this->assertSame('s3', $borrowed->disk);
        Storage::disk('s3')->assertExists($original->path);

        // The row is the same row: alt, anchor and role are this locale's and
        // stay put.
        $this->assertSame('Post-renovation cleaning', $borrowed->alt);
    }

    #[Test]
    public function a_section_picture_is_drawn_again_from_its_heading(): void
    {
        $unit = $this->article('How to clean grout', target: 'grout cleaning');
        $this->picture($unit, AssetRole::Inline, 'Tools you need');

        $this->redraw()->assertSuccessful();

        $call = $this->images->calls()[0];
        $this->assertStringContainsString('grout cleaning: Tools you need', $call['prompt']);
        $this->assertSame((int) config('media.inline.height'), $call['options']['height']);
    }

    #[Test]
    public function a_checked_article_stays_publishable_after_its_urls_change(): void
    {
        $unit = $this->article('How to clean grout');
        $this->picture($unit, AssetRole::Inline, 'Tools you need');
        $this->seal($unit);

        $facts = app(ArticleBusinessFacts::class);
        $this->assertNull($facts->refusal($unit->refresh()));

        $this->redraw()->assertSuccessful();

        $this->assertNull($facts->refusal($unit->refresh()), 'A URL swap is not a change to anything that was checked.');
    }

    #[Test]
    public function an_article_already_waiting_for_a_human_is_not_blessed_by_the_swap(): void
    {
        $unit = $this->article('How to clean grout');
        $this->picture($unit, AssetRole::Inline, 'Tools you need');
        $this->seal($unit, 'a-hash-of-some-older-body');

        $this->redraw()->assertSuccessful();

        $this->assertNotNull(app(ArticleBusinessFacts::class)->refusal($unit->refresh()));
    }

    #[Test]
    public function a_published_article_is_sent_again_only_where_it_already_is(): void
    {
        $unit = $this->article('How to clean grout', published: true);
        $asset = $this->picture($unit, AssetRole::Hero, $unit->title);

        $holding = Channel::factory()->create(['verified_at' => now()]);
        $other = Channel::factory()->create(['verified_at' => now()]);
        WebhookDelivery::factory()->create([
            'channel_id' => $holding->id,
            'content_item_id' => $unit->id,
            'payload_snapshot' => ['event' => WebhookEvent::Published->value],
        ]);

        $this->redraw()->assertSuccessful();

        $queued = WebhookDelivery::query()->where('content_item_id', $unit->id)->where('status', 'pending')->get();
        $this->assertCount(1, $queued);
        $this->assertSame($holding->id, $queued[0]->channel_id);
        $this->assertSame(WebhookEvent::Updated->value, $queued[0]->payload_snapshot['event']);
        $this->assertSame(
            Storage::disk('s3')->url($asset->refresh()->path),
            $queued[0]->payload_snapshot['content']['images'][0]['url'],
        );
        $this->assertFalse(WebhookDelivery::query()->where('channel_id', $other->id)->exists());
    }

    #[Test]
    public function a_dry_run_changes_nothing_and_says_what_it_would_cost(): void
    {
        $unit = $this->article('How to clean grout');
        $asset = $this->picture($unit, AssetRole::Hero, $unit->title);
        $this->picture($unit, AssetRole::Inline, 'Tools you need', onDisk: true);
        $body = $unit->body_markdown;

        $this->redraw(['--dry' => true])
            ->expectsOutputToContain('Would draw 1 picture(s), about $0.04.')
            ->assertSuccessful();

        $this->assertSame([], $this->images->prompts());
        $this->assertSame('public', $asset->refresh()->disk);
        $this->assertSame($body, $unit->refresh()->body_markdown);
    }

    #[Test]
    public function the_limit_caps_what_is_drawn_and_not_what_is_copied(): void
    {
        $unit = $this->article('How to clean grout');
        $this->picture($unit, AssetRole::Hero, $unit->title);
        $this->picture($unit, AssetRole::Inline, 'Tools you need');
        $copied = $this->picture($unit, AssetRole::Inline, 'How long it takes', onDisk: true);

        $this->redraw(['--limit' => 1])->assertSuccessful();

        $this->assertCount(1, $this->images->prompts());
        $this->assertSame('s3', $copied->refresh()->disk);
        $this->assertSame(1, Asset::query()->where('disk', 'public')->count());
    }

    #[Test]
    public function an_article_with_a_pipeline_in_flight_is_left_for_the_next_run(): void
    {
        $unit = $this->article('How to clean grout');
        $asset = $this->picture($unit, AssetRole::Hero, $unit->title);
        PipelineRun::factory()->running()->create(['content_item_id' => $unit->id]);

        $this->redraw()->assertSuccessful();

        $this->assertSame([], $this->images->prompts());
        $this->assertSame('public', $asset->refresh()->disk);
    }

    #[Test]
    public function pictures_already_on_the_media_disk_and_retired_ones_are_left_alone(): void
    {
        $unit = $this->article('How to clean grout');
        $this->picture($unit, AssetRole::Hero, $unit->title)->forceFill(['disk' => 's3'])->save();
        $this->picture($unit, AssetRole::Inline, 'Tools you need')->forceFill(['superseded_at' => now()])->save();

        $this->redraw()->assertSuccessful();

        $this->assertSame([], $this->images->prompts());
    }

    #[Test]
    public function a_picture_paid_for_and_then_refused_by_the_disk_is_counted_and_stops_the_run(): void
    {
        $provider = $this->failingProvider(paid: true);
        $first = $this->article('How to clean grout');
        $this->picture($first, AssetRole::Hero, $first->title);
        $second = $this->article('How to clean ovens');
        $this->picture($second, AssetRole::Hero, $second->title);

        $this->redraw(['--limit' => 5])
            ->expectsOutputToContain('The media disk refused a write.')
            ->expectsOutputToContain('Drew 0 of 1 attempted picture(s), about $0.04.')
            ->assertFailed();

        $this->assertSame(1, $provider->calls, 'Every later file would have been drawn and billed the same way.');
    }

    #[Test]
    public function a_failed_draw_still_counts_against_the_limit(): void
    {
        $provider = $this->failingProvider(paid: false);
        $first = $this->article('How to clean grout');
        $this->picture($first, AssetRole::Hero, $first->title);
        $second = $this->article('How to clean ovens');
        $this->picture($second, AssetRole::Hero, $second->title);

        $this->redraw(['--limit' => 1])->assertFailed();

        $this->assertSame(1, $provider->calls);
    }

    #[Test]
    public function a_limit_that_is_not_a_whole_number_is_refused_rather_than_ignored(): void
    {
        $unit = $this->article('How to clean grout');
        $this->picture($unit, AssetRole::Hero, $unit->title);

        foreach (['one', '-1', '1.5'] as $limit) {
            $this->redraw(['--limit' => $limit])
                ->expectsOutputToContain('--limit must be a whole number')
                ->assertFailed();
        }

        $this->assertSame([], $this->images->prompts());
    }

    #[Test]
    public function an_article_is_sent_again_once_when_its_last_picture_moves(): void
    {
        $unit = $this->article('How to clean grout', published: true);
        $this->picture($unit, AssetRole::Hero, $unit->title);
        $this->picture($unit, AssetRole::Inline, 'Tools you need');
        $channel = $this->holding($unit);

        $this->redraw(['--limit' => 1])->assertSuccessful();

        $this->assertSame(0, $this->pending($unit), 'Half its pictures are still broken; one update, when it is whole.');

        $this->redraw()->assertSuccessful();

        $this->assertSame(1, $this->pending($unit));
        $this->assertSame($channel->id, WebhookDelivery::query()->where('status', 'pending')->value('channel_id'));
    }

    #[Test]
    public function the_update_is_queued_with_the_move_so_a_run_that_dies_later_does_not_lose_it(): void
    {
        $fixed = $this->article('How to clean grout', published: true);
        $this->picture($fixed, AssetRole::Hero, $fixed->title, onDisk: true);
        $this->holding($fixed);

        // Drawn after the copy above, and refused by the disk: the run stops
        // before any end-of-run step could have happened.
        $this->failingProvider(paid: true);
        $broken = $this->article('How to clean ovens');
        $this->picture($broken, AssetRole::Hero, $broken->title);

        $this->redraw()->assertFailed();

        $this->assertSame(1, $this->pending($fixed));
    }

    /** @param  array<string, mixed>  $options */
    private function redraw(array $options = []): PendingCommand
    {
        // `artisan()` is typed `PendingCommand|int`; the int only comes back
        // with mocking switched off, which this suite never does.
        /** @var PendingCommand $pending */
        $pending = $this->artisan('media:redraw', ['project' => $this->project->slug, ...$options]);

        return $pending;
    }

    /**
     * An image provider that fails every call, after the vendor billed it or before.
     *
     * @return ImageGenerationProvider&object{calls: int}
     */
    private function failingProvider(bool $paid): ImageGenerationProvider
    {
        $provider = new class($paid) implements ImageGenerationProvider
        {
            public int $calls = 0;

            public function __construct(private readonly bool $paid) {}

            public function name(): string
            {
                return 'failing';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            /**
             * @param  list<string>  $references
             * @param  array<string, mixed>  $options
             */
            public function generate(string $prompt, array $references = [], array $options = []): GeneratedImage
            {
                $this->calls++;

                if ($this->paid) {
                    throw (new MediaWriteFailed('Could not write to the s3 disk.'))->withSpend('failing', 'model', 40_000);
                }

                throw new RuntimeException('The provider timed out.');
            }
        };

        $this->app->instance(ImageGenerationProvider::class, $provider);

        return $provider;
    }

    /** A verified channel that already received this article. */
    private function holding(ContentItem $unit): Channel
    {
        $channel = Channel::factory()->create(['verified_at' => now()]);
        WebhookDelivery::factory()->create([
            'channel_id' => $channel->id,
            'content_item_id' => $unit->id,
            'payload_snapshot' => ['event' => WebhookEvent::Published->value],
        ]);

        return $channel;
    }

    private function pending(ContentItem $unit): int
    {
        return WebhookDelivery::query()->where('content_item_id', $unit->id)->where('status', 'pending')->count();
    }

    private function article(string $title, bool $published = false, ?string $target = null): ContentItem
    {
        $factory = ContentItem::factory();

        return ($published ? $factory->published() : $factory->draft())->create([
            'title' => $title,
            'target_query' => $target,
            'body_markdown' => "# {$title}\n\nIntro.\n\n## Tools you need\n\nText.\n\n## How long it takes\n\nText.\n",
        ]);
    }

    /**
     * A picture recorded on `public`, placed in the body the way
     * IllustrateDraft places it, with its file there or gone.
     */
    private function picture(
        ContentItem $unit,
        AssetRole $role,
        string $alt,
        bool $onDisk = false,
        ?string $path = null,
    ): Asset {
        $path ??= 'generated/'.Str::random(24).'.webp';

        if ($onDisk) {
            Storage::disk('public')->put($path, 'bytes');
        }

        if ($role === AssetRole::Inline) {
            $markdown = preg_replace(
                '/^(## '.preg_quote($alt, '/').')$/m',
                "$1\n\n![{$alt}](http://localhost/storage/{$path})",
                (string) $unit->body_markdown,
            );
            $unit->forceFill(['body_markdown' => $markdown])->save();
        }

        return Asset::factory()->create([
            'content_item_id' => $unit->id,
            'role' => $role,
            'anchor' => $role === AssetRole::Inline ? Str::slug($alt) : null,
            'disk' => 'public',
            'path' => $path,
            'alt' => $alt,
        ]);
    }

    private function seal(ContentItem $unit, ?string $hash = null): void
    {
        $run = PipelineRun::factory()->create(['content_item_id' => $unit->id]);

        DB::table('article_business_contexts')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $this->project->id,
            'content_item_id' => $unit->id,
            'pipeline_run_id' => $run->id,
            'prompt' => 'Business facts.',
            'body_hash' => $hash ?? app(ArticleBusinessFacts::class)->bodyHash($unit->refresh()),
            'created_at' => now(),
        ]);
    }
}
