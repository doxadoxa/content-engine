<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Models\PageProposalRevision;
use App\Models\PageSnapshot;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/** Conservative preservation check of the captured body, not browser layout or form execution. */
final class PublicPageStructure
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /** @param list<array<string,mixed>>|null $changes */
    public function preserved(PageProposalRevision $revision, PageSnapshot $live, ?array $changes = null): bool
    {
        $source = $revision->sourceSnapshot;
        if ($source === null) {
            return false;
        }
        $before = $this->document((string) ($source->fields['body_html'] ?? ''));
        $after = $this->document((string) ($live->fields['body_html'] ?? ''));
        $sourceBlocks = $this->blocks->from($source);
        $beforeNodes = $this->blocks->elements($before);
        $afterNodes = $this->blocks->elements($after);
        $changes = collect($changes ?? $revision->changes)->keyBy('locator');
        $offset = 0;
        foreach ($sourceBlocks as $index => $block) {
            $change = $changes->get($block['id']);
            $beforeNode = $beforeNodes[$index] ?? null;
            $afterNode = $afterNodes[$index + $offset] ?? null;
            if ($beforeNode === null || $afterNode === null) {
                return false;
            }
            if ($change === null) {
                continue;
            }
            if ($change['operation'] === 'insert_after') {
                $inserted = $afterNodes[$index + $offset + 1] ?? null;
                // One new plain paragraph is the only supported insertion in assisted mode.
                if ($inserted === null || $inserted->tagName !== 'p' || $inserted->getElementsByTagName('*')->length !== 0 || $inserted->hasAttributes()
                    || $afterNode->parentNode !== $inserted->parentNode || $this->nextElement($afterNode) !== $inserted
                    || PageBlocks::normalize($inserted->textContent) !== PageBlocks::normalize($change['after'])) {
                    return false;
                }
                $inserted->parentNode?->removeChild($inserted);
                $offset++;
            } elseif ($change['kind'] === 'text_section') {
                // Only text may differ: inline tags, attributes and their positions remain evidence.
                $this->maskText($beforeNode);
                $this->maskText($afterNode);
            } elseif ($change['kind'] === 'internal_link') {
                $links = $afterNode->getElementsByTagName('a');
                if ($links->length !== 1) {
                    return false;
                }
                $link = $links->item(0);
                if ($link === null || PageBlocks::normalize($link->textContent) !== $change['anchor_text']) {
                    return false;
                }
                // PublicVerification separately checks this exact approved destination.
                while ($link->firstChild !== null) {
                    $link->parentNode?->insertBefore($link->firstChild, $link);
                }
                $link->parentNode?->removeChild($link);
                $afterNode->normalize();
            }
        }

        return $this->signature($before) === $this->signature($after);
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function nextElement(DOMNode $node): ?DOMElement
    {
        while ($node = $node->nextSibling) {
            if ($node instanceof DOMElement) {
                return $node;
            }
            if ($node instanceof DOMText && PageBlocks::normalize($node->textContent) !== '') {
                return null;
            }
        }

        return null;
    }

    private function maskText(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $child->nodeValue = '[reviewed text]';
            } else {
                $this->maskText($child);
            }
        }
    }

    /** @return list<mixed> */
    private function signature(DOMNode $node): array
    {
        $result = [];
        if ($node instanceof DOMElement) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                // Rotating anti-CSRF values are not content; retain the control, name and other attributes.
                if ($attribute->name === 'value' && $node->tagName === 'input' && strtolower($node->getAttribute('type')) === 'hidden'
                    && preg_match('/^(?:_token|csrf_token|_csrf|_wpnonce|.*_nonce)$/i', $node->getAttribute('name'))) {
                    continue;
                }
                $attributes[$attribute->name] = $attribute->value;
            }
            ksort($attributes);
            $result[] = ['element' => $node->tagName, 'attributes' => $attributes];
        } elseif ($node instanceof DOMText) {
            $text = PageBlocks::normalize($node->textContent);
            if ($text !== '') {
                $result[] = ['text' => $text];
            }
        }
        foreach ($node->childNodes as $child) {
            $result = [...$result, ...$this->signature($child)];
        }
        if ($node instanceof DOMElement) {
            $result[] = ['end' => $node->tagName];
        }

        return $result;
    }
}
