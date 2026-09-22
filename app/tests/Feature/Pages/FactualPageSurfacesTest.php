<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Models\Project;
use App\Pages\FactualPageSurfaces;
use App\Pages\PublicPageReader;
use DOMDocument;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FactualPageSurfacesTest extends TestCase
{
    public function test_header_footer_and_structured_assertions_are_separate_from_public_edit_fields(): void
    {
        Http::fake(['example.com/*' => Http::response($this->html('Open Monday.', '{"openingHours":"Mo 09:00-18:00"}'))]);
        $project = new Project(['website_url' => 'https://example.com', 'default_locale' => 'en', 'locales' => ['en']]);
        $read = app(PublicPageReader::class)->read($project, 'https://example.com/en/service', 'en');
        $facts = $read['fact_surfaces'];
        /** @var list<array<string, mixed>> $surfaces */
        $surfaces = $facts['surfaces'];
        $this->assertSame('captured', $facts['status']);
        $this->assertSame([], $facts['omitted']);
        $body = collect($surfaces)->firstWhere('kind', 'delivered_body_text');
        $this->assertStringContainsString('Lisbon only', $body['text']);
        $this->assertStringContainsString('Open Monday.', $body['text']);
        $this->assertStringNotContainsString('arbitrary script', $body['text']);
        $this->assertStringNotContainsString('Open Monday.', $read['fields']['body_text']);
        $this->assertSame('Cleaning service.', $read['fields']['body_text']);
        $json = collect($surfaces)->firstWhere('kind', 'json_ld');
        $this->assertSame('{"openingHours":"Mo 09:00-18:00"}', $json['text']);
        $this->assertSame('json', $json['format']);
        $this->assertCount(2, collect($surfaces)->where('kind', 'meta_description'));
    }

    public function test_footer_changes_have_separate_evidence_hashes_preserving_the_edit_contract(): void
    {
        Http::fake(['example.com/*' => Http::sequence()->push($this->html('Open Monday.'))->push($this->html('Closed Monday.'))]);
        $project = new Project(['website_url' => 'https://example.com', 'default_locale' => 'en', 'locales' => ['en']]);
        $before = app(PublicPageReader::class)->read($project, 'https://example.com/en/service', 'en');
        $after = app(PublicPageReader::class)->read($project, 'https://example.com/en/service', 'en');
        $this->assertSame($before['fields'], $after['fields']);
        $this->assertSame($before['content_hash'], $after['content_hash']);
        $this->assertNotSame($before['fact_surfaces']['content_hash'], $after['fact_surfaces']['content_hash']);
    }

    public function test_limits_and_invalid_structured_data_are_explicit_incomplete_evidence(): void
    {
        $document = new DOMDocument;
        $document->loadHTML($this->html(str_repeat('Long factual paragraph. ', 50000), '{invalid json}'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $facts = app(FactualPageSurfaces::class)->capture($document);
        /** @var list<array<string, mixed>> $surfaces */
        $surfaces = $facts['surfaces'];
        $this->assertSame('partial', $facts['status']);
        $this->assertCount(256, $facts['surfaces']);
        $this->assertCount(2, $facts['omitted']);
        $this->assertGreaterThan(0, $facts['omitted'][1]['characters']);
        $body = collect($surfaces)->where('kind', 'delivered_body_text')->values();
        $this->assertSame(mb_substr($body[0]['text'], -200), mb_substr($body[1]['text'], 0, 200));
    }

    private function html(string $footer, string $json = '{}'): string
    {
        return '<html lang="en"><head><title>Cleaning</title><meta name="description" content="Service description"><meta name="description" content="Another observed description"><script type="application/ld+json">'.$json.'</script></head><body><header><p>Lisbon only</p></header><main><p>Cleaning service.</p></main><footer><p>'.$footer.'</p></footer><script>arbitrary script</script></body></html>';
    }
}
