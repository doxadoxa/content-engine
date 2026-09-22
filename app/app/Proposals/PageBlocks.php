<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Models\PageSnapshot;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/** Plain-text leaf blocks for precise assisted handoff. These are not CMS selectors. */
final class PageBlocks
{
    /** @return list<array{id: string, text: string, tag: string, links: list<array{text: string, href: string}>}> */
    public function from(PageSnapshot $snapshot): array
    {
        return $this->html((string) ($snapshot->fields['body_html'] ?? ''));
    }

    /** @return list<array{id: string, text: string, tag: string, links: list<array{text: string, href: string}>}> */
    public function html(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $blocks = [];
        foreach ($this->elements($document) as $node) {
            $text = self::normalize($node->textContent);
            $links = [];
            foreach ($node->getElementsByTagName('a') as $link) {
                $links[] = ['text' => self::normalize($link->textContent), 'href' => $link->getAttribute('href')];
            }
            $blocks[] = ['id' => 'block-'.count($blocks).'-'.substr(hash('sha256', $text), 0, 12), 'text' => $text, 'tag' => $node->tagName, 'links' => $links];
        }

        return $blocks;
    }

    /** @return list<DOMElement> */
    public function elements(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//p[not(.//p)]|//h1|//h2|//h3|//h4|//li[not(.//p or .//li)]');
        $result = [];
        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $excluded = $xpath->query('ancestor::form|ancestor::nav|ancestor::header|ancestor::footer|ancestor::script', $node);
            $text = self::normalize($node->textContent);
            if (($excluded !== false && $excluded->length > 0) || $text === '' || mb_strlen($text) > 2400) {
                continue;
            }
            $result[] = $node;
        }

        return $result;
    }

    public static function normalize(string $text): string
    {
        return Str::squish(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
