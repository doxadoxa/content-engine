<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PageSnapshot;
use App\Pages\PageUrl;
use App\Proposals\PageBlocks;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Pin visible theme effects before approval; this never expands a CMS write. */
final class RenderedTitleChanges
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /** @return list<array<string,mixed>> */
    public function forTitle(PageSnapshot $public, string $before, string $after, bool $wordpress): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.($public->fields['body_html'] ?? ''), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $elements = $this->blocks->elements($document);
        $headings = array_values(array_filter($elements, static fn (DOMElement $node): bool => $node->tagName === 'h1' && PageBlocks::normalize($node->textContent) === PageBlocks::normalize($before)));
        if (count($headings) !== 1) {
            throw new UnsupportedPageChange('The visible page heading cannot be mapped unambiguously. Use an assisted handoff.');
        }
        $targets = $headings;
        if ($wordpress) {
            // Core query loops can include this same post. Only a declared post-title
            // block linking to the exact tracked identity is a known coupled effect.
            $nodes = (new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-post-title ")]');
            foreach ($nodes ?: [] as $node) {
                if (! $node instanceof DOMElement || $node === $headings[0] || PageBlocks::normalize($node->textContent) !== PageBlocks::normalize($before)) {
                    continue;
                }
                $links = $node->getElementsByTagName('a');
                if ($links->length !== 1) {
                    throw new UnsupportedPageChange('Another visible title occurrence has an ambiguous theme mapping. Use an assisted handoff.');
                }
                try {
                    $isThisPage = PageUrl::resolve($public->source_url, $links->item(0)?->getAttribute('href') ?? '') === ($public->metadata['canonical_url'] ?? $public->source_url);
                } catch (\InvalidArgumentException) {
                    $isThisPage = false;
                }
                if ($isThisPage) {
                    $targets[] = $node;
                }
            }
        }
        $changes = [];
        $blocks = $this->blocks->from($public);
        foreach ($elements as $index => $element) {
            $contained = array_filter($targets, fn (DOMElement $target): bool => $this->contains($element, $target));
            if ($contained === []) {
                continue;
            }
            $block = $blocks[$index];
            if (count($contained) !== 1 || substr_count($block['text'], PageBlocks::normalize($before)) !== 1) {
                throw new UnsupportedPageChange('A repeated visible title cannot be bounded to one exact occurrence. Use an assisted handoff.');
            }
            $changes[] = ['kind' => 'text_section', 'operation' => 'replace', 'locator' => $block['id'], 'before' => $block['text'], 'after' => str_replace(PageBlocks::normalize($before), PageBlocks::normalize($after), $block['text'])];
        }

        return $changes;
    }

    private function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        do {
            if ($node === $ancestor) {
                return true;
            }
        } while ($node = $node->parentNode);

        return false;
    }
}
