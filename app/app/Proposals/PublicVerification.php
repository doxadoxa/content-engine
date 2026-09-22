<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Models\PageProposalRevision;
use App\Models\PageSnapshot;
use App\Pages\PageUrl;

final class PublicVerification
{
    public function __construct(private readonly PageBlocks $blocks, private readonly PublicPageStructure $structure) {}

    /** @return array{passed: bool, fields: list<array{field: string, passed: bool, detail: string}>} */
    public function compare(PageProposalRevision $revision, PageSnapshot $live): array
    {
        $source = $revision->sourceSnapshot;
        $beforeBlocks = $source ? $this->blocks->from($source) : [];
        $liveBlocks = $this->blocks->from($live);
        /** @var list<array<string,mixed>> $changes */
        $changes = [...$revision->changes, ...($revision->compiled_patch['rendered_changes'] ?? [])];
        $byLocator = collect($changes)->keyBy('locator');
        $expected = [];
        foreach ($beforeBlocks as $block) {
            $change = $byLocator->get($block['id']);
            $expected[] = $change && $change['operation'] === 'replace' && $change['kind'] === 'text_section' ? PageBlocks::normalize($change['after']) : $block['text'];
            if ($change && $change['operation'] === 'insert_after') {
                $expected[] = PageBlocks::normalize($change['after']);
            }
        }
        $fields = [];
        $identity = ($live->metadata['canonical_url'] ?? null) === $revision->canonical_url && ($live->metadata['locale'] ?? null) === $revision->locale;
        $fields[] = ['field' => 'Page identity', 'passed' => $identity, 'detail' => $identity ? 'Canonical URL and language preserved.' : 'The public canonical URL or language changed.'];
        foreach (['title', 'description'] as $field) {
            $target = $byLocator->get($field)['after'] ?? $source?->fields[$field] ?? '';
            $passed = PageBlocks::normalize((string) ($live->fields[$field] ?? '')) === PageBlocks::normalize((string) $target);
            if ($field === 'description' && $byLocator->has('description')) {
                $passed = $passed && ($live->metadata['description_count'] ?? 1) === 1;
            }
            $fields[] = ['field' => $field, 'passed' => $passed, 'detail' => $passed ? 'The supported public field matches the reviewed result.' : 'The public field does not match the reviewed result.'];
        }
        $textMatches = array_column($liveBlocks, 'text') === $expected;
        $fields[] = ['field' => 'Page text blocks', 'passed' => $textMatches, 'detail' => $textMatches ? 'Reviewed text changes match; other extracted text blocks are preserved.' : 'The live text differs from the reviewed changes or another extracted block changed.'];
        $preserved = $this->structure->preserved($revision, $live, $changes);
        $fields[] = ['field' => 'Captured structure, forms and existing links', 'passed' => $preserved, 'detail' => $preserved ? 'The captured body structure, form controls and existing links are preserved. This does not test visual layout, scripts or form submission.' : 'An unapproved element, form control, existing link or other captured body content changed. Review the public page before continuing.'];
        foreach ($changes as $change) {
            if ($change['kind'] !== 'internal_link') {
                continue;
            }
            $matches = collect($liveBlocks)->where('text', $change['before'])->values();
            $passed = false;
            if ($matches->count() === 1) {
                foreach ($matches[0]['links'] as $link) {
                    try {
                        if ($link['text'] === $change['anchor_text'] && PageUrl::resolve($live->source_url, $link['href']) === $change['target_url']) {
                            $passed = true;
                        }
                    } catch (\InvalidArgumentException) {
                        // An unreadable live link cannot verify the reviewed target.
                    }
                }
            }
            $fields[] = ['field' => 'Internal link: '.$change['anchor_text'], 'passed' => $passed, 'detail' => $passed ? 'The reviewed phrase links to the exact approved page.' : 'The reviewed phrase or target is missing or different.'];
        }

        return ['passed' => ! in_array(false, array_column($fields, 'passed'), true), 'fields' => $fields];
    }
}
