<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PageSnapshot;
use App\Proposals\PageBlocks;

final class CustomPatchCompiler
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array{field: string, operation: string, before: string, after: string}>
     */
    public function compile(PageSnapshot $public, PageSnapshot $editable, array $changes): array
    {
        $format = $editable->metadata['content_format'] ?? null;
        if (! in_array($format, ['plain_fields', 'markdown_gfm'], true)) {
            throw new UnsupportedPageChange('This custom source format requires an assisted handoff.');
        }
        $blocks = collect($this->blocks->from($public))->keyBy('id');
        $patches = [];
        $usedFields = [];
        foreach ($changes as $change) {
            $kind = $change['kind'];
            $before = (string) $change['before'];
            $after = (string) $change['after'];
            $operation = $change['operation'];
            if (in_array($kind, ['title', 'description'], true)) {
                $field = $kind;
                $stored = (string) ($editable->fields[$field] ?? '');
                if ($kind === 'title') {
                    $after = WordPressPatchCompiler::untemplate($before, $after, $stored);
                } elseif (! in_array($editable->metadata['description_owner'] ?? null, ['avyo_page_override', 'article_locale'], true)
                    || PageBlocks::normalize($stored) !== PageBlocks::normalize($before)
                    || ($public->metadata['description_count'] ?? 0) > 1) {
                    throw new UnsupportedPageChange('The public description does not match the explicitly owned locale source.');
                }
                $before = $stored;
            } else {
                $block = $blocks->get($change['locator']);
                if ($block === null || $block['text'] !== $before || $blocks->where('text', $before)->count() !== 1) {
                    throw new UnsupportedPageChange('The selected public block is missing or ambiguous.');
                }
                if ($format === 'plain_fields') {
                    $field = 'intro';
                    $stored = (string) ($editable->fields['intro'] ?? '');
                    if ($kind !== 'text_section' || $operation !== 'replace' || $block['links'] !== [] || PageBlocks::normalize($stored) !== $before) {
                        throw new UnsupportedPageChange('Only the service page introduction can be replaced here. Other sections require assistance.');
                    }
                    $before = $stored;
                } else {
                    $field = 'body_markdown';
                    $raw = (string) ($editable->fields[$field] ?? '');
                    $paragraphs = array_values(array_filter(explode("\n\n", $raw), fn (string $paragraph): bool => $this->plain($paragraph) && PageBlocks::normalize($paragraph) === $before));
                    if (count($paragraphs) !== 1 || substr_count($raw, $paragraphs[0]) !== 1 || $block['links'] !== []) {
                        throw new UnsupportedPageChange('This block does not map to a unique plain Markdown paragraph. Use an assisted handoff.');
                    }
                    $before = $paragraphs[0];
                    if ($kind === 'internal_link') {
                        if ($change['anchor_text'] !== $block['text']) {
                            throw new UnsupportedPageChange('This Markdown receiver supports whole-paragraph links only. Use assistance for a shorter anchor.');
                        }
                        $after = (string) $change['target_url'];
                        if (! filter_var($after, FILTER_VALIDATE_URL) || parse_url($after, PHP_URL_SCHEME) !== 'https' || parse_url($after, PHP_URL_USER) || preg_match('/[()\s<>]/', $after)) {
                            throw new UnsupportedPageChange('The target cannot be represented by the supported Markdown link codec.');
                        }
                        $operation = 'link';
                    } elseif (! $this->plain($after)) {
                        throw new UnsupportedPageChange('Use a plain paragraph without Markdown syntax or an assisted handoff.');
                    }
                }
            }
            if (! in_array($field, $editable->editable_fields, true) || in_array($field, $usedFields, true)) {
                throw new UnsupportedPageChange('This source field is unavailable or has more than one edit. Use a smaller revision or assistance.');
            }
            $usedFields[] = $field;
            $patches[] = compact('field', 'operation', 'before', 'after');
        }

        return $patches;
    }

    private function plain(string $text): bool
    {
        return $text !== '' && trim($text) === $text && mb_strlen($text) <= 2400
            && ! preg_match('/[\r\n<>`*_\[\]\\\\|~]|^(?:#|>|[-+] |\d+[.)] )|&(?:#\d+|#x[0-9a-f]+|[a-z]+);/iu', $text);
    }
}
