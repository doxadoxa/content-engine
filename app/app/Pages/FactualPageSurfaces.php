<?php

declare(strict_types=1);

namespace App\Pages;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use JsonException;

/** Observed public assertions, never confirmed business evidence. */
final class FactualPageSurfaces
{
    private const CHUNK_CHARACTERS = 4000;

    private const OVERLAP_CHARACTERS = 200;

    private const MAX_SURFACES = 256;

    /** @return array<string, mixed> */
    public function capture(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $sources = [];
        $omitted = [];
        foreach ($xpath->query('//title|//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]') ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }
            $text = $node->nodeName === 'meta' ? $node->attributes?->getNamedItem('content')?->nodeValue : $node->textContent;
            $sources[] = ['kind' => $node->nodeName === 'meta' ? 'meta_description' : 'title', 'locator' => $node->getNodePath(), 'format' => 'text', 'text' => Str::squish((string) $text)];
        }
        $bodyParts = [];
        foreach ($xpath->query('//body//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::svg or ancestor::template)]') ?: [] as $node) {
            if ($node instanceof DOMNode && trim($node->textContent) !== '') {
                $bodyParts[] = $node->textContent;
            }
        }
        $sources[] = ['kind' => 'delivered_body_text', 'locator' => '/html/body', 'format' => 'text', 'text' => Str::squish(implode(' ', $bodyParts))];
        foreach ($xpath->query('//script[translate(normalize-space(@type),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }
            $raw = trim($node->textContent);
            try {
                $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new JsonException('Structured data must be an object or array.');
                }
            } catch (JsonException) {
                $omitted[] = ['locator' => $node->getNodePath(), 'reason' => 'Structured data could not be parsed as a JSON object or array.', 'characters' => mb_strlen($raw), 'content_hash' => hash('sha256', $raw)];

                continue;
            }
            $sources[] = ['kind' => 'json_ld', 'locator' => $node->getNodePath(), 'format' => 'json', 'text' => $raw];
        }

        $surfaces = [];
        $observedCharacters = 0;
        foreach ($sources as $source) {
            $length = mb_strlen($source['text']);
            $observedCharacters += $length;
            for ($offset = 0; $offset < $length; $offset += self::CHUNK_CHARACTERS - self::OVERLAP_CHARACTERS) {
                if (count($surfaces) >= self::MAX_SURFACES) {
                    $omitted[] = ['locator' => $source['locator'], 'reason' => 'The 256-section capture limit was reached.', 'characters' => $length - $offset];
                    break;
                }
                $text = mb_substr($source['text'], $offset, self::CHUNK_CHARACTERS);
                $surfaces[] = [
                    'id' => hash('sha256', $source['kind'].'|'.$source['locator'].'|'.$offset.'|'.$text),
                    'kind' => $source['kind'], 'locator' => $source['locator'], 'format' => $source['format'],
                    'text' => $text, 'offset' => $offset, 'total_characters' => $length,
                ];
                if ($offset + self::CHUNK_CHARACTERS >= $length) {
                    break;
                }
            }
        }

        return [
            'version' => 1, 'status' => $omitted === [] ? 'captured' : 'partial',
            'content_hash' => hash('sha256', json_encode([$sources, $omitted], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'surfaces' => $surfaces, 'observed_characters' => $observedCharacters, 'omitted' => $omitted,
            'limitations' => [
                'These are assertions in the delivered HTML, not confirmed business facts.',
                'Body text includes headers, footers and hidden text. Browser visibility, script-generated content, images and form behavior are not inspected.',
                'Ordinary scripts, styles, SVG, templates, noscript text and attributes other than description metadata are outside this capture.',
                'Sections use Unicode character offsets and may overlap by 200 characters. Capture does not mean that every section has been fact-checked.',
            ],
        ];
    }
}
