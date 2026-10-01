<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\ModelRequest;
use App\Onboarding\Contracts\SiteReader;
use App\Support\Brand\SitePalette;
use Illuminate\Support\Str;
use Throwable;

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
        private readonly SiteScreenshot $screenshots,
    ) {}

    /**
     * @return array{snapshot: SiteSnapshot, analysis: SiteAnalysis}
     */
    public function analyse(string $url): array
    {
        $snapshot = $this->reader->read($url);
        // Taken before the model call and used after it, so a site that reads
        // as empty still gets its colours: a one-page site built entirely in
        // images has no text to interpret and a palette worth having.
        $palette = $this->palette($url);

        if ($snapshot->isEmpty()) {
            // Nothing readable. Better to say so than to hand a model an empty
            // page and let it invent a business.
            return [
                'snapshot' => $snapshot,
                'analysis' => $this->blank($snapshot)->withPalette($palette),
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
                // Narrow on purpose. "Touches money, health or safety" was the
                // old question, and every business that takes a payment or
                // mentions sport answered yes — which put a scheduling tool for
                // sports clubs behind mandatory review and a named author it
                // had no reason to need. The test is the *articles*: would
                // following them badly hurt a reader's health, money, legal
                // position or safety?
                'YMYL: "yes" or "no", then " — " and a reason of at most ten words.',
                '  Yes only when articles for this business would give advice a reader acts on',
                '  where a mistake could harm their health, finances, legal position or safety:',
                '  medicine, mental health, medication, supplements, diet for a condition,',
                '  investing, loans, tax, insurance, legal advice, gambling, crypto, weapons,',
                '  home or child safety. A business in one of these areas is yes even when it',
                '  is a shop — a pharmacy, a supplement store or a car-seat retailer.',
                '  Otherwise no, including: software and SaaS (even for payments or clinics),',
                '  event organising, sports and fitness clubs or venues, ordinary shops,',
                '  restaurants, travel, cleaning, marketing, and any business merely because it',
                '  charges money.',
                '  Write "yes" or "no" in English, whatever language the site is in.',
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
            'analysis' => $this->parse($answer->text, $snapshot)->withPalette($palette),
        ];
    }

    private function parse(string $text, SiteSnapshot $snapshot): SiteAnalysis
    {
        $fields = [];

        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            // `**YMYL:** yes` as well as `YMYL: yes` — models bold labels.
            if (preg_match('/^\**([A-Z]+)\**:\**\s*(.*)$/u', trim($line), $m) === 1) {
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
        $ymyl = $this->ymyl($fields['YMYL'] ?? '');

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
            isYmyl: $ymyl['isYmyl'],
            ymylReason: $ymyl['ymylReason'],
        );
    }

    /**
     * The verdict and the reason given for it.
     *
     * The reason is kept so the owner can see why their site was read this way
     * — and so support can tell a wrong reading from a right one when they ask.
     *
     * @return array{isYmyl: bool, ymylReason: string}
     */
    private function ymyl(string $answer): array
    {
        $answer = trim($answer, " \t\"'*");
        $parts = preg_split('/\s*(?:—|–|-|:|,|;|\()\s*/u', $answer, 2) ?: [$answer];
        $verdict = mb_strtolower(trim(preg_split('/\s+/u', trim($parts[0]))[0] ?? '', " \"'*.!"));

        // Asked for in English, but a model writing about a Portuguese site
        // sometimes answers in Portuguese — and a missed yes is the costly
        // mistake here, since it skips review.
        $yes = preg_match('/^(y|yes|sí|sì|si|sim|ja|oui|да|так|tak)$/u', $verdict) === 1;

        return [
            'isYmyl' => $yes,
            'ymylReason' => Str::limit(trim($parts[1] ?? '', " \"'*.)"), 160, ''),
        ];
    }

    /**
     * The site's colours, counted off a picture of it.
     *
     * **Never fails the analysis.** Every reason this can come back empty — no
     * renderer on this deployment, a site that will not load in a browser, a
     * page that is genuinely just black on white — is a reason to have no
     * suggestion, not a reason to lose the interpretation of the copy that
     * already succeeded. The one exception is an unsafe address, which
     * {@see SiteScreenshot} throws for and which the reader above would have
     * refused first anyway.
     *
     * @return array{fill: string, ink: string, accent: string|null}|null
     */
    private function palette(string $url): ?array
    {
        try {
            $site = $this->screenshots->inspect($url);
        } catch (Throwable) {
            return null;
        }

        if ($site === null) {
            return null;
        }

        // The stylesheet first and the photograph second, matching
        // {@see \App\Onboarding\Jobs\ReadSitePalette}. Onboarding reads the same
        // site the Brand Brief's button reads, and the two answering differently
        // for the same page is the kind of disagreement nobody reports and
        // everybody stops trusting.
        return (SitePalette::fromDeclared($site->colours) ?? SitePalette::fromPng($site->png))?->toArray();
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
