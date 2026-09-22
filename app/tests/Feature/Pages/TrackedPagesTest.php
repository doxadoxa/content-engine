<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Enums\SitePageKind;
use App\Models\BusinessFact;
use App\Models\ContentItem;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\PageDiscovery;
use App\Pages\PageUrl;
use App\Pages\TrackedPageIdentity;
use App\Pages\TrackedPages;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TrackedPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_existing_article_is_snapshotted_without_becoming_a_confirmed_fact_or_commercial_source(): void
    {
        [$owner, $project] = $this->owner();
        $prior = SitePage::factory()->create(['url' => 'https://example.com/en/article', 'page_kind' => SitePageKind::Editorial, 'body' => null]);
        Http::fake(['example.com/*' => Http::response($this->html())]);

        $this->actingAs($owner)->post('/pages', ['url' => $prior->url, 'locale' => 'en', 'kind' => 'editorial'])->assertRedirect('/pages/'.$prior->id);
        $this->assertSame($prior->id, SitePage::query()->tracked()->sole()->id);
        $snapshot = PageSnapshot::query()->sole();
        $this->assertStringContainsString('Actual existing article', $snapshot->fields['body_text']);
        $this->assertStringNotContainsString('secret script', $snapshot->fields['body_text']);
        $this->assertSame([], $snapshot->editable_fields);
        $this->assertSame('public', $snapshot->source_kind);
        $this->assertNull($prior->fresh()->body);
        $this->assertSame(0, BusinessFact::query()->count());
        $this->assertSame($project->id, $snapshot->project_id);
    }

    #[Test]
    public function canonical_aliases_reuse_one_tracked_identity_and_link_an_existing_content_item(): void
    {
        [$owner] = $this->owner();
        $item = ContentItem::factory()->published()->create(['public_url' => 'https://example.com/en/article']);
        Http::fake(['example.com/*' => Http::response($this->html())]);
        $this->actingAs($owner)->post('/pages', ['url' => 'https://example.com/old?utm_source=news#read', 'locale' => 'en', 'kind' => 'editorial'])->assertRedirect();
        $page = SitePage::query()->tracked()->sole();
        $this->assertSame($item->id, $page->content_item_id);
        $this->actingAs($owner)->post('/pages', ['url' => 'https://example.com/en/article', 'locale' => 'en', 'kind' => 'editorial'])->assertRedirect('/pages/'.$page->id);
        $this->assertSame(1, SitePage::query()->tracked()->count());
        $this->assertSame(2, PageSnapshot::query()->count());
    }

    #[Test]
    public function a_captured_requested_alias_resolves_to_its_current_page_within_the_tenant(): void
    {
        [, $project] = $this->owner();
        Http::fake(['example.com/*' => Http::response($this->html())]);
        $page = app(TrackedPages::class)->track($project, 'https://example.com/old?utm_source=ad', 'en', 'editorial');
        $this->assertSame('https://example.com/old', $page->latestSnapshot->metadata['requested_url']);
        $this->assertSame($page->id, app(TrackedPageIdentity::class)->find('https://example.com/old?utm_source=search')?->id);
        app(CurrentProject::class)->run(Project::factory()->create(), function (): void {
            $this->assertNull(app(TrackedPageIdentity::class)->find('https://example.com/old'));
        });
        $page->update(['tracked_at' => null]);
        $this->assertNull(app(TrackedPageIdentity::class)->find('https://example.com/old'));
    }

    #[Test]
    public function a_changed_canonical_does_not_create_a_second_tracked_identity_during_capture(): void
    {
        [$owner, $project] = $this->owner();
        Http::fake(['example.com/*' => Http::sequence()->push($this->html())->push($this->html(canonical: '/en/replacement'))]);
        $page = app(TrackedPages::class)->track($project, 'https://example.com/en/article', 'en', 'editorial');
        $this->actingAs($owner)->post('/pages/'.$page->id.'/snapshots')->assertSessionHasErrors('url');
        $this->assertSame(1, SitePage::query()->tracked()->count());
        $this->assertSame(1, PageSnapshot::query()->count());
        $this->assertSame('https://example.com/en/article', $page->fresh()->canonical_url);
    }

    #[Test]
    public function captures_keep_prior_text_and_do_not_mutate_commercial_evidence(): void
    {
        [$owner, $project] = $this->owner();
        Http::fake(['example.com/*' => Http::sequence()->push($this->html('Old text'))->push($this->html('New text'))]);
        $page = app(TrackedPages::class)->track($project, 'https://example.com/en/article', 'en', 'commercial');
        $page->update(['body' => 'Separately curated business evidence']);
        $before = $page->latestSnapshot;
        $this->actingAs($owner)->post('/pages/'.$page->id.'/snapshots')->assertRedirect();
        $this->assertSame('Separately curated business evidence', $page->fresh()->body);
        $this->assertStringContainsString('Old text', $before->fresh()->fields['body_text']);
        $this->assertStringContainsString('New text', $page->fresh()->latestSnapshot->fields['body_text']);
        $this->assertNotSame($before->content_hash, $page->fresh()->latestSnapshot->content_hash);
    }

    #[Test]
    public function outside_origins_private_targets_and_redirects_are_refused_before_any_unsafe_fetch(): void
    {
        [$owner] = $this->owner();
        Http::fake(['example.com/bounce' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);
        $this->actingAs($owner)->post('/pages', ['url' => 'http://127.0.0.1/admin', 'locale' => 'en', 'kind' => 'commercial'])->assertSessionHasErrors('url');
        $this->post('/pages', ['url' => 'https://other.example/page', 'locale' => 'en', 'kind' => 'commercial'])->assertSessionHasErrors('url');
        Http::assertNothingSent();
        $this->post('/pages', ['url' => 'https://example.com/bounce', 'locale' => 'en', 'kind' => 'commercial'])->assertSessionHasErrors('url');
        Http::assertSentCount(1);
        $this->assertSame(0, PageSnapshot::query()->count());
    }

    #[Test]
    public function cross_origin_canonical_and_mismatched_language_do_not_create_a_snapshot(): void
    {
        [$owner] = $this->owner();
        Http::fake(['example.com/*' => Http::sequence()->push($this->html(canonical: 'https://other.example/page'))->push(str_replace('lang="en"', 'lang="pt"', $this->html()))]);
        $this->actingAs($owner)->post('/pages', ['url' => 'https://example.com/en/article', 'locale' => 'en', 'kind' => 'editorial'])->assertSessionHasErrors('url');
        $this->post('/pages', ['url' => 'https://example.com/en/article', 'locale' => 'en', 'kind' => 'editorial'])->assertSessionHasErrors('locale');
        $this->assertSame(0, PageSnapshot::query()->count());
    }

    #[Test]
    public function a_canonical_path_cannot_silently_move_a_page_into_another_configured_language(): void
    {
        [$owner] = $this->owner();
        Http::fake(['example.com/*' => Http::response($this->html(canonical: '/pt/article'))]);
        $this->actingAs($owner)->post('/pages', ['url' => 'https://example.com/en/article', 'locale' => 'en', 'kind' => 'editorial'])->assertSessionHasErrors('locale');
        $this->assertSame(0, PageSnapshot::query()->count());
    }

    #[Test]
    public function snapshot_models_refuse_mutation(): void
    {
        [, $project] = $this->owner();
        Http::fake(['example.com/*' => Http::response($this->html())]);
        $page = app(TrackedPages::class)->track($project, 'https://example.com/en/article', 'en', 'editorial');
        $this->expectException(LogicException::class);
        $page->latestSnapshot->update(['fields' => ['title' => 'tampered']]);
    }

    #[Test]
    public function discovery_reads_sitemap_indexes_but_skips_off_site_urls_without_importing_them(): void
    {
        [, $project] = $this->owner();
        Http::fake([
            'example.com/sitemap.xml' => Http::response('<sitemapindex><sitemap><loc>https://example.com/pages.xml</loc></sitemap></sitemapindex>'),
            'example.com/pages.xml' => Http::response('<urlset><url><loc>https://example.com/en/article</loc></url><url><loc>http://127.0.0.1/private</loc></url><url><loc>http://[broken</loc></url></urlset>'),
        ]);
        $result = app(PageDiscovery::class)->discover($project);
        $this->assertSame(1, $result['found']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, SitePage::query()->tracked()->count());
        $this->assertSame(0, PageSnapshot::query()->count());
        Http::assertSentCount(2);
    }

    #[Test]
    public function operators_can_read_but_cannot_track_or_change_fact_evidence(): void
    {
        [, $project] = $this->owner();
        $operator = User::factory()->create();
        $operator->projects()->attach($project, ['role' => 'operator']);
        $this->actingAs($operator)->get('/pages')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('site-pages/index'));
        $this->post('/pages', ['url' => 'https://example.com/en/article', 'locale' => 'en', 'kind' => 'editorial'])->assertForbidden();
        $this->post('/business-facts', [])->assertForbidden();
        Http::assertNothingSent();
    }

    #[Test]
    public function cross_tenant_page_binding_is_hidden_and_tracking_can_be_paused_without_erasing_history(): void
    {
        [$owner, $project] = $this->owner();
        Http::fake(['example.com/*' => Http::response($this->html())]);
        $page = app(TrackedPages::class)->track($project, 'https://example.com/en/article', 'en', 'editorial');
        $other = User::factory()->create();
        $other->projects()->attach(Project::factory()->create(), ['role' => 'owner']);
        $this->actingAs($other)->get('/pages/'.$page->id)->assertNotFound();
        $this->actingAs($owner)->post('/pages/'.$page->id.'/untrack')->assertRedirect('/pages');
        app(CurrentProject::class)->set($project);
        $this->assertNull($page->fresh()->tracked_at);
        $this->assertSame(1, PageSnapshot::query()->count());
    }

    #[Test]
    public function the_database_refuses_cross_tenant_content_links(): void
    {
        [, $project] = $this->owner();
        $item = app(CurrentProject::class)->run(Project::factory()->create(), fn () => ContentItem::factory()->create());
        app(CurrentProject::class)->set($project);
        $this->expectException(QueryException::class);
        SitePage::factory()->create(['content_item_id' => $item->id]);
    }

    #[Test]
    public function downloads_over_the_limit_are_rejected(): void
    {
        Http::fake(['example.com/*' => Http::response(str_repeat('x', 50))]);
        $this->expectException(UnsafePublicUrl::class);
        app(PublicHttpClient::class)->request('GET', 'https://example.com/page', maxBytes: 20);
    }

    #[Test]
    public function url_identity_preserves_meaningful_queries_and_does_not_collapse_distinct_paths(): void
    {
        $this->assertSame('https://example.com/en/page?service=deep', PageUrl::normalize('https://EXAMPLE.COM/en/page?utm_source=ad&service=deep#read'));
        $this->assertNotSame(PageUrl::normalize('https://example.com/a'), PageUrl::normalize('https://example.com/a/'));
    }

    /** @return array{User, Project} */
    private function owner(): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['website_url' => 'https://example.com', 'sitemap_url' => 'https://example.com/sitemap.xml', 'default_locale' => 'en', 'locales' => ['en', 'pt']]);
        $owner->projects()->attach($project, ['role' => 'owner']);
        app(CurrentProject::class)->set($project);

        return [$owner, $project];
    }

    private function html(string $text = 'Actual existing article', string $canonical = '/en/article'): string
    {
        return '<html lang="en"><head><title>Existing guide</title><meta content="Useful description" name="description"><link rel="canonical" href="'.$canonical.'"></head><body><nav>Furniture</nav><main><h1>'.$text.'</h1><p>Body detail</p></main><script>secret script</script></body></html>';
    }
}
