<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PageSnapshot;
use App\Proposals\PageBlocks;

final class WordPressPatchCompiler
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /** @param list<array<string,mixed>> $changes
     * @return list<array{field:string,operation:string,before:string,after:string}>
     */
    public function compile(PageSnapshot $public, PageSnapshot $editable, array $changes): array
    {
        $patches = [];
        $raw = (string) ($editable->fields['body_html'] ?? '');
        $blocks = collect($this->blocks->from($public))->keyBy('id');
        foreach ($changes as $change) {
            $kind = $change['kind'];
            if (in_array($kind, ['title', 'description'], true)) {
                $this->editable($editable, $kind);
                $before = (string) ($editable->fields[$kind] ?? '');
                $after = $change['after'];
                if ($kind === 'title') {
                    $after = self::untemplate($change['before'], $after, $before);
                } elseif (($editable->metadata['description_owner'] ?? '') !== 'avyo' || PageBlocks::normalize($before) !== PageBlocks::normalize($change['before']) || ($public->metadata['description_count'] ?? 0) > 1) {
                    throw new UnsupportedPageChange('The public description has another or ambiguous owner. Use an assisted handoff.');
                }
                $patches[] = ['field' => $kind, 'operation' => 'replace', 'before' => $before, 'after' => $after];

                continue;
            }
            $this->editable($editable, 'body_html');
            $block = $blocks->get($change['locator']);
            if ($block === null) {
                throw new UnsupportedPageChange('The public source block is no longer available.');
            }
            preg_match_all('~<(p|h[1-6]|li)(?:\s[^>]*)?>([^<>]*)</\1>~s', $raw, $matches, PREG_SET_ORDER);
            $matching = array_values(array_filter($matches, static fn (array $match): bool => PageBlocks::normalize($match[2]) === $block['text']));
            if (count($matching) !== 1) {
                throw new UnsupportedPageChange('This public text does not map to one plain editable source block. Use an assisted handoff.');
            }
            $sourceBlock = $matching[0];
            $before = $sourceBlock[2];
            $after = htmlspecialchars($change['after'], ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            $operation = $change['operation'];
            if ($kind === 'internal_link') {
                $before = htmlspecialchars($change['anchor_text'], ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
                if (! str_contains($sourceBlock[2], $before)) {
                    throw new UnsupportedPageChange('The anchor encoding cannot be mapped exactly to editable source.');
                }
                $after = $change['target_url'];
                $operation = 'link';
            } elseif ($operation === 'insert_after') {
                preg_match_all('~<!-- wp:paragraph -->\s*<p>[^<>\[\]]+</p>\s*<!-- /wp:paragraph -->~s', $raw, $paragraphs);
                $anchors = array_values(array_filter($paragraphs[0], static fn (string $value): bool => str_contains($value, $sourceBlock[0])));
                if (count($anchors) !== 1) {
                    throw new UnsupportedPageChange('Insertions require one complete plain core paragraph. Use an assisted handoff.');
                }
                $before = $anchors[0];
                $after = '<!-- wp:paragraph -->'."\n".'<p>'.$after.'</p>'."\n".'<!-- /wp:paragraph -->';
            }
            if ($before === '' || substr_count($raw, $before) !== 1) {
                throw new UnsupportedPageChange('The editable fragment occurs more than once. Use an assisted handoff.');
            }
            $patches[] = ['field' => 'body_html', 'operation' => $operation, 'before' => $before, 'after' => $after];
        }

        return $patches;
    }

    public static function untemplate(string $publicBefore, string $publicAfter, string $storedBefore): string
    {
        if ($storedBefore === '' || substr_count($publicBefore, $storedBefore) !== 1) {
            throw new UnsupportedPageChange('The public title cannot be mapped to the stored title unambiguously.');
        }
        $position = (int) strpos($publicBefore, $storedBefore);
        $prefix = substr($publicBefore, 0, $position);
        $suffix = substr($publicBefore, $position + strlen($storedBefore));
        if (! str_starts_with($publicAfter, $prefix) || ! str_ends_with($publicAfter, $suffix) || strlen($publicAfter) <= strlen($prefix) + strlen($suffix)) {
            throw new UnsupportedPageChange('Keep the current title prefix and suffix, or use an assisted handoff.');
        }

        return substr($publicAfter, strlen($prefix), strlen($publicAfter) - strlen($prefix) - strlen($suffix));
    }

    private function editable(PageSnapshot $snapshot, string $field): void
    {
        if (! in_array($field, $snapshot->editable_fields, true)) {
            throw new UnsupportedPageChange((string) ($snapshot->metadata['unsupported_reason'] ?? $snapshot->metadata['body_unsupported_reason'] ?? 'The receiver does not own this field. Use an assisted handoff.'));
        }
    }
}
