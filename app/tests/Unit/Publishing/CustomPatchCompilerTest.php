<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Models\Channel;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Proposals\PageBlocks;
use App\Publishing\Pages\CustomPatchCompiler;
use App\Publishing\Pages\NativePatchCompiler;
use App\Publishing\Pages\PageReceiverClient;
use App\Publishing\Pages\UnsupportedPageChange;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** In-memory source snapshots only: no database, receiver request or DNS lookup. */
final class CustomPatchCompilerTest extends TestCase
{
    public function test_service_intro_maps_only_the_owned_field_and_preserves_catalog_heading(): void
    {
        $public = $this->public('<h1>Home cleaning</h1><p>A  clear introduction.</p><form><input name="email"></form>');
        $editable = $this->editable('plain_fields', ['title' => 'Home cleaning', 'description' => 'Original description.', 'intro' => 'A  clear introduction.']);
        $change = $this->change($public, 'A clear introduction.', 'A reviewed introduction.');

        $this->assertSame([['field' => 'intro', 'operation' => 'replace', 'before' => 'A  clear introduction.', 'after' => 'A reviewed introduction.']], $this->compile($public, $editable, [$change]));
        $this->assertSame('Home cleaning', $editable->fields['title']);
    }

    public function test_markdown_compiles_one_raw_paragraph_without_reconstructing_other_source(): void
    {
        $public = $this->public('<h1>Preparation</h1><p>A clear introduction.</p><h2>Keep this heading</h2><p>Keep <em>formatted text</em>.</p>');
        $raw = "A  clear introduction.\n\n## Keep this heading\n\nKeep *formatted text*.\n\n[Existing link](https://example.com/help)";
        $editable = $this->editable('markdown_gfm', ['body_markdown' => $raw]);
        $patch = $this->compile($public, $editable, [$this->change($public, 'A clear introduction.', 'A reviewed introduction.')])[0];

        $this->assertSame('A  clear introduction.', $patch['before']);
        $this->assertSame('body_markdown', $patch['field']);
        $this->assertSame("A reviewed introduction.\n\n## Keep this heading\n\nKeep *formatted text*.\n\n[Existing link](https://example.com/help)", str_replace($patch['before'], $patch['after'], $raw));
        $this->assertSame($raw, $editable->fields['body_markdown']);
    }

    public function test_plain_paragraph_insertion_and_whole_paragraph_link_are_explicit_operations(): void
    {
        $public = $this->public('<p>A clear introduction.</p>');
        $editable = $this->editable('markdown_gfm', ['body_markdown' => 'A clear introduction.']);
        $insert = $this->change($public, 'A clear introduction.', 'An additional supported paragraph.');
        $insert['operation'] = 'insert_after';
        $this->assertSame('insert_after', $this->compile($public, $editable, [$insert])[0]['operation']);
        $link = $this->change($public, 'A clear introduction.', '');
        $link = [...$link, 'kind' => 'internal_link', 'anchor_text' => 'A clear introduction.', 'target_url' => 'https://example.com/service'];
        $this->assertSame([['field' => 'body_markdown', 'operation' => 'link', 'before' => 'A clear introduction.', 'after' => 'https://example.com/service']], $this->compile($public, $editable, [$link]));
    }

    #[DataProvider('unsafeMarkdown')]
    public function test_unsupported_or_ambiguous_markdown_requires_assistance(string $raw, string $html, string $after): void
    {
        $public = $this->public($html);
        $editable = $this->editable('markdown_gfm', ['body_markdown' => $raw]);
        $this->expectException(UnsupportedPageChange::class);

        $this->compile($public, $editable, [$this->change($public, 'A clear introduction.', $after)]);
    }

    /** @return array<string, array{string,string,string}> */
    public static function unsafeMarkdown(): array
    {
        return [
            'existing inline markup' => ['A *clear* introduction.', '<p>A clear introduction.</p>', 'Reviewed paragraph.'],
            'duplicate raw paragraph' => ["A clear introduction.\n\nA clear introduction.", '<p>A clear introduction.</p>', 'Reviewed paragraph.'],
            'duplicate public block' => ['A clear introduction.', '<p>A clear introduction.</p><p>A clear introduction.</p>', 'Reviewed paragraph.'],
            'existing public link' => ['A clear introduction.', '<p><a href="/service">A clear introduction.</a></p>', 'Reviewed paragraph.'],
            'HTML replacement' => ['A clear introduction.', '<p>A clear introduction.</p>', '<b>Reviewed paragraph.</b>'],
            'Markdown replacement' => ['A clear introduction.', '<p>A clear introduction.</p>', '*Reviewed paragraph.*'],
            'multiline replacement' => ['A clear introduction.', '<p>A clear introduction.</p>', "Reviewed\nparagraph."],
        ];
    }

    public function test_short_link_anchor_requires_assistance(): void
    {
        $public = $this->public('<p>A clear introduction.</p>');
        $editable = $this->editable('markdown_gfm', ['body_markdown' => 'A clear introduction.']);
        $change = [...$this->change($public, 'A clear introduction.', ''), 'kind' => 'internal_link', 'anchor_text' => 'introduction', 'target_url' => 'https://example.com/service'];
        $this->expectException(UnsupportedPageChange::class);
        $this->compile($public, $editable, [$change]);
    }

    public function test_unavailable_locale_field_cannot_be_changed_even_when_public_text_matches(): void
    {
        $public = $this->public('<p>Fallback introduction.</p>');
        $editable = $this->editable('plain_fields', ['title' => 'Exact locale title', 'intro' => 'Fallback introduction.']);
        $editable->editable_fields = ['title'];
        $this->expectException(UnsupportedPageChange::class);
        $this->compile($public, $editable, [$this->change($public, 'Fallback introduction.', 'New introduction.')]);
    }

    public function test_two_separate_body_changes_require_a_smaller_revision(): void
    {
        $public = $this->public('<p>First paragraph.</p><p>Second paragraph.</p>');
        $editable = $this->editable('markdown_gfm', ['body_markdown' => "First paragraph.\n\nSecond paragraph."]);
        $this->expectException(UnsupportedPageChange::class);
        $this->compile($public, $editable, [$this->change($public, 'First paragraph.', 'First reviewed.'), $this->change($public, 'Second paragraph.', 'Second reviewed.')]);
    }

    public function test_description_needs_one_public_description_and_explicit_source_ownership(): void
    {
        $public = $this->public('<p>Body.</p>');
        $editable = $this->editable('markdown_gfm', ['description' => 'Original description.']);
        $change = ['kind' => 'description', 'operation' => 'replace', 'locator' => 'description', 'before' => 'Original description.', 'after' => 'Reviewed description.'];
        $this->assertSame('description', $this->compile($public, $editable, [$change])[0]['field']);
        $public->metadata = ['description_count' => 2];
        $this->expectException(UnsupportedPageChange::class);
        $this->compile($public, $editable, [$change]);
    }

    #[DataProvider('titleSemantics')]
    public function test_explicit_custom_title_semantics_control_the_coupled_heading(bool $affectsHeading, string $type): void
    {
        $public = $this->public('<h1>Home cleaning</h1><p>Existing paragraph.</p>');
        $editable = $this->editable($type === 'service' ? 'plain_fields' : 'markdown_gfm', ['title' => 'Home cleaning']);
        $project = (new Project)->forceFill(['id' => 'project-test', 'website_url' => 'https://8.8.8.8']);
        $channel = (new Channel)->forceFill(['id' => 'channel-test', 'project_id' => $project->id, 'type' => 'webhook', 'is_enabled' => true, 'secret' => 'isolated-test-secret', 'config' => ['page_receiver_base' => 'https://8.8.8.8/api/avyo/pages/v1']]);
        $channel->syncOriginal();
        $page = (new SitePage)->forceFill(['project_id' => $project->id, 'canonical_url' => 'https://8.8.8.8/en/service', 'locale' => 'en', 'cms_object_id' => $type.'-1-en', 'cms_object_type' => $type]);
        $page->setRelation('project', $project)->setRelation('channel', $channel);
        $editable->metadata = [...$editable->metadata, 'title_affects_heading' => $affectsHeading, 'destination' => app(PageReceiverClient::class)->destination($channel, $page)];
        $change = ['kind' => 'title', 'operation' => 'replace', 'locator' => 'title', 'before' => 'Home cleaning - Cleaning Point', 'after' => 'Local home cleaning - Cleaning Point'];
        $result = app(NativePatchCompiler::class)->compile($page, $public, $editable, [$change]);

        $this->assertSame('supported', $result['compiled_patch']['status']);
        $this->assertSame('Local home cleaning', $result['compiled_patch']['patches'][0]['after']);
        $this->assertCount($affectsHeading ? 1 : 0, $result['compiled_patch']['rendered_changes']);
    }

    /** @return array<string, array{bool,string}> */
    public static function titleSemantics(): array
    {
        return ['service SEO title leaves matching H1 intact' => [false, 'service'], 'article title also edits H1' => [true, 'article']];
    }

    private function public(string $html): PageSnapshot
    {
        return new PageSnapshot(['fields' => ['title' => 'Home cleaning - Cleaning Point', 'description' => 'Original description.', 'body_html' => $html], 'metadata' => ['description_count' => 1]]);
    }

    /** @param array<string,string> $fields */
    private function editable(string $format, array $fields): PageSnapshot
    {
        return new PageSnapshot(['fields' => $fields, 'editable_fields' => array_keys($fields), 'captured_at' => now(), 'metadata' => ['content_format' => $format, 'description_owner' => $format === 'plain_fields' ? 'avyo_page_override' : 'article_locale']]);
    }

    /** @return array<string,mixed> */
    private function change(PageSnapshot $public, string $before, string $after): array
    {
        $block = collect(app(PageBlocks::class)->from($public))->firstWhere('text', $before);
        $this->assertNotNull($block);

        return ['kind' => 'text_section', 'operation' => 'replace', 'locator' => $block['id'], 'before' => $before, 'after' => $after];
    }

    /** @param list<array<string,mixed>> $changes
     * @return list<array{field:string,operation:string,before:string,after:string}>
     */
    private function compile(PageSnapshot $public, PageSnapshot $editable, array $changes): array
    {
        return app(CustomPatchCompiler::class)->compile($public, $editable, $changes);
    }
}
