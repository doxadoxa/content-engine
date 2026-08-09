<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\ModelRequest;
use App\Onboarding\Contracts\SiteReader;
use Illuminate\Support\Str;

/**
 * Read a site, then say what the business is (§3.1, and the first step of the
 * onboarding wizard).
 *
 * Two stages on purpose. The reading is facts — title, headings, copy — and is
 * kept verbatim. The interpretation is a model's opinion and is labelled as
 * suggestions the operator edits. Blurring the two is how an onboarding flow
 * ends up confidently wrong about somebody's business with no way to see where
 * it went wrong.
 */
class SiteAnalyst
{
    public function __construct(
        private readonly SiteReader $reader,
        private readonly ModelGateway $models,
    ) {}

    /**
     * @return array{snapshot: SiteSnapshot, analysis: SiteAnalysis}
     */
    public function analyse(string $url): array
    {
        $snapshot = $this->reader->read($url);

        if ($snapshot->isEmpty()) {
            // Nothing readable. Better to say so than to hand a model an empty
            // page and let it invent a business.
            return [
                'snapshot' => $snapshot,
                'analysis' => $this->blank($snapshot),
            ];
        }

        $answer = $this->models->send(new ModelRequest(
            role: 'utility',
            instructions: implode("\n", [
                'You read a company website and describe the business behind it.',
                'Answer only with these labels, one per line, list items separated by " | ":',
                'NAME: ...',
                'DESCRIPTION: two sentences, what they do and for whom',
                'AUDIENCES: a | b | c',
                'TONE: how their existing copy sounds',
                'VISUAL: what their imagery looks like, or what it should',
                'COMPETITORS: domain | domain',
                // Head terms, not marketing copy. These are handed to a
                // keyword API that matches by containment: "premium home
                // cleaning Lisbon" contains nothing anybody searches for and
                // returns an empty pool, while "cleaning lisbon" returns the
                // long tail around it. Getting this wrong produces a project
                // that onboards cleanly and then has nothing to write about.
                'KEYWORDS: 4 to 8 SHORT search terms, two or three words each, of the kind',
                '  that expand into a long tail — "cleaning lisbon", not "premium home',
                '  cleaning services in Lisbon". No brand names, no adjectives like',
                '  premium or professional, no full sentences.',
                // In LANGUAGE, not in the market's language. An English site
                // seeded with Portuguese terms gets Portuguese titles on
                // English articles — which is what "limpeza casa lisboa" as an
                // en-locale unit looked like — and it could not rank for those
                // queries anyway, because the page it points at is in English.
                '  Write them in LANGUAGE, the language this site is written in.',
                'FORBIDDEN: claims or topics this business should never make',
                'LANGUAGE: BCP 47 tag of the site',
                'MARKET: ISO country code they sell in, or "us" if global',
                'YMYL: yes or no — does this touch money, health or safety',
                'Never invent a fact. If the page does not say, leave the line empty.',
            ]),
            prompt: implode("\n\n", array_filter([
                "URL: {$snapshot->url}",
                $snapshot->title === '' ? null : "Title: {$snapshot->title}",
                $snapshot->description === '' ? null : "Meta description: {$snapshot->description}",
                $snapshot->headings === [] ? null : "Headings:\n- ".implode("\n- ", array_slice($snapshot->headings, 0, 25)),
                $snapshot->links === [] ? null : 'Pages: '.implode(', ', array_slice($snapshot->links, 0, 25)),
                "Copy:\n{$snapshot->text}",
            ])),
        ));

        return [
            'snapshot' => $snapshot,
            'analysis' => $this->parse($answer->text, $snapshot),
        ];
    }

    private function parse(string $text, SiteSnapshot $snapshot): SiteAnalysis
    {
        $fields = [];

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            if (preg_match('/^([A-Z]+):\s*(.*)$/u', trim($line), $m) === 1) {
                $fields[$m[1]] = trim($m[2]);
            }
        }

        $list = static function (string $key) use ($fields): array {
            $raw = $fields[$key] ?? '';

            return array_values(array_filter(
                array_map(trim(...), explode('|', $raw)),
                static fn (string $item): bool => $item !== '',
            ));
        };

        $language = $fields['LANGUAGE'] ?? '';

        return new SiteAnalysis(
            name: $fields['NAME'] ?? $this->nameFrom($snapshot),
            description: $fields['DESCRIPTION'] ?? '',
            audiences: $list('AUDIENCES'),
            tone: $fields['TONE'] ?? '',
            visualLanguage: $fields['VISUAL'] ?? '',
            competitors: $list('COMPETITORS'),
            seedKeywords: $list('KEYWORDS'),
            forbidden: $list('FORBIDDEN'),
            // The page's own `lang` wins over the model's guess: it is a fact
            // and the guess is not.
            language: $snapshot->language ?: ($language ?: 'en'),
            market: strtolower($fields['MARKET'] ?? '') ?: 'us',
            isYmyl: Str::startsWith(mb_strtolower($fields['YMYL'] ?? ''), 'y'),
        );
    }

    private function blank(SiteSnapshot $snapshot): SiteAnalysis
    {
        return new SiteAnalysis(
            name: $this->nameFrom($snapshot),
            description: '',
            audiences: [],
            tone: '',
            visualLanguage: '',
            competitors: [],
            seedKeywords: [],
            forbidden: [],
            language: $snapshot->language ?: 'en',
            market: 'us',
            isYmyl: false,
        );
    }

    private function nameFrom(SiteSnapshot $snapshot): string
    {
        if ($snapshot->title !== '') {
            // Marketing titles are "Brand — tagline"; the brand is the bit
            // before the punctuation.
            $parts = preg_split('/[|\-–—:]/u', $snapshot->title);

            return $parts === false ? $snapshot->title : trim($parts[0]);
        }

        return Str::headline(Str::before(parse_url($snapshot->url, PHP_URL_HOST) ?: 'project', '.'));
    }
}
