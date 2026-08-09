<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Asset;
use App\Models\ContentItem;

/**
 * What an article ships with, as a checklist and a number.
 *
 * Every check reads the finished article rather than trusting the step that was
 * supposed to produce it: "the GEO step ran" and "there is FAQ schema on this
 * page" are different claims, and only the second is worth showing to somebody
 * deciding whether to publish.
 *
 * The score is the share of checks that passed, weighted — a missing hero is
 * cosmetic, a missing fact-check on a money-or-health article is not. It is a
 * summary of the list below it and never a substitute for reading it.
 */
class ArticleScore
{
    public function __construct(
        private readonly HouseStyle $style,
        private readonly Readability $readability,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     publishable: bool,
     *     blocking: list<string>,
     *     checks: list<array{key: string, label: string, ok: bool, detail: string, severity: string}>,
     * }
     */
    public function for(ContentItem $item): array
    {
        $body = (string) $item->body_markdown;

        $locale = (string) $item->locale;

        // Null when no rule in the guide can be honestly applied to this
        // language: the word lists are English phrases and the punctuation
        // rules are English and Portuguese typography.
        $violations = $this->style->violations($body, $locale);
        $checksVocabulary = $this->style->checksVocabulary($locale);
        $limitations = $this->style->hasLimitations($body, $locale);

        $reading = $this->readability->measure($body, $locale);

        $checks = [
            // The house style, weighted heavily: uniform prose is the thing a
            // reader recognises before they notice anything else about an
            // article. See product/humanizated-articles.md.
            $violations === null
                ? $this->check(
                    'reads_human',
                    'Reads as written, not generated',
                    true,
                    'not measured for '.$locale,
                    weight: 0,
                    severity: 'note',
                )
                : $this->check(
                    'reads_human',
                    'Reads as written, not generated',
                    $violations === [],
                    match (true) {
                        $violations !== [] => implode('; ', $violations),
                        // Said out loud rather than reported as a clean pass.
                        // The word lists are English; elsewhere only the
                        // typography ran, and an operator comparing 100 in
                        // English against 100 in Portuguese should know the
                        // second was a shorter exam.
                        ! $checksVocabulary => 'punctuation only — the word lists are English',
                        default => 'no tells found',
                    },
                    weight: 3,
                    // Prose that reads as generated is the one failure a reader
                    // notices before anything else about an article.
                    severity: 'critical',
                ),
            // Null means no phrasings are listed for this language. Left as a
            // failed critical check it would make every article in that
            // language unpublishable for something it probably did.
            $limitations === null
                ? $this->check(
                    'limitations',
                    'Names real limitations',
                    true,
                    'not measured for '.$locale,
                    weight: 0,
                    severity: 'note',
                )
                : $this->check(
                    'limitations',
                    'Names real limitations',
                    $limitations,
                    $limitations ? 'present' : 'no section says where this does not fit',
                    weight: 3,
                    // §6.1 of the style guide calls this the highest-leverage
                    // rule there is: it is what gets a piece read and cited
                    // rather than skimmed, and it cannot be faked.
                    severity: 'critical',
                ),
            $this->check(
                'rhythm',
                'Uneven rhythm',
                $this->style->rhythmIsVaried($body),
                $this->style->rhythmIsVaried($body) ? 'sentence length varies' : 'sentences are too uniform',
                weight: 2,
            ),
            $this->check(
                'structure',
                'Optimal content structure',
                count($item->outline) >= 3,
                count($item->outline).' sections',
                weight: 2,
            ),
            $this->check(
                'internal_links',
                'Internal links',
                count($item->internal_links) > 0,
                count($item->internal_links).' links',
                weight: 2,
            ),
            // Skipped entirely when nothing citable existed. An article about
            // a local service has no public body to point at, and marking it
            // down for not inventing one would reward the articles that do.
            ...($item->offered_sources === []
                ? []
                : [
                    $this->check(
                        'external_links',
                        'External links',
                        $this->externalLinks($body) > 0,
                        $this->externalLinks($body).' links',
                    ),
                    $this->check(
                        'sources',
                        'Cited sources',
                        $this->hasSources($item),
                        $this->hasSources($item) ? 'fact-checked' : 'nothing cited',
                    ),
                ]),
            $this->check(
                'quotes',
                'Expert quotes',
                $this->blockquotes($body) > 0,
                $this->blockquotes($body).' quoted',
            ),
            $this->check(
                'statistics',
                'Statistics and data points',
                $this->figures($body) >= 3,
                $this->figures($body).' figures',
            ),
            $this->check(
                'images',
                'Image alt texts',
                $this->imagesWithAlt($item) > 0,
                $this->imagesWithAlt($item).' of '.$item->assets->count().' with alt text',
            ),
            $this->check(
                'semantic_keywords',
                'Semantic keywords',
                $item->entity_coverage !== [] && ! in_array(false, $item->entity_coverage, true),
                count(array_filter($item->entity_coverage)).' of '.count($item->entity_coverage).' entities covered',
                weight: 2,
            ),
            $this->check(
                'faq',
                'FAQ section',
                $item->faq_json_ld !== [],
                $item->faq_json_ld === [] ? 'none' : 'present',
            ),
            $this->check(
                'meta',
                'Optimised meta tags',
                $this->metaIsGood($item),
                mb_strlen((string) $item->summary).'/160 characters',
                weight: 2,
            ),
            $this->check(
                'json_ld',
                'JSON-LD schema',
                $item->json_ld !== [],
                $item->json_ld === [] ? 'none' : 'present',
            ),
            $this->check(
                'quotable',
                'Quotable blocks',
                count($item->quotable_blocks) > 0,
                count($item->quotable_blocks).' blocks',
            ),

            // Skipped outside the languages the formula was built for. Flesch
            // constants were fitted to English; run on Portuguese they give a
            // confident number that means nothing, and somebody would act on
            // it. Not measured is a fairer answer than measured wrongly.
            ...($reading === null
                ? [$this->check(
                    'readability',
                    'Reading level',
                    true,
                    'not measured for '.$item->locale,
                    weight: 0,
                    severity: 'note',
                )]
                : [
                    $this->check(
                        'readability',
                        'Reading level',
                        $this->readability->isComfortable($reading),
                        // In the words of whichever scale answered: English
                        // gives a school grade where lower is easier, the
                        // Portuguese and Russian adaptations give reading ease
                        // where higher is. Printing "grade 62" would be worse
                        // than printing nothing.
                        $this->readability->describe($reading),
                        severity: 'warning',
                    ),
                    // The passive test is a fact about English grammar — a form
                    // of "to be" plus a past participle. Russian builds the
                    // passive with -ся and short participles, Portuguese with
                    // ser and agreement, so outside English there is no reading
                    // to report and a 0% would look like a clean pass.
                    ...($reading['passive_ratio'] === null
                        ? []
                        : [$this->check(
                            'passive_voice',
                            'Active voice',
                            $reading['passive_ratio'] <= 0.2,
                            sprintf('%d%% of sentences passive', (int) round($reading['passive_ratio'] * 100)),
                            severity: 'suggestion',
                        )]),
                ]),
        ];

        $critical = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! $check['ok'] && $check['severity'] === 'critical',
        ));

        return [
            'score' => $this->score($checks),
            // Both, because they answer different questions. A piece can score
            // 88 and still be unfit to publish, and an operator reading only
            // the number would never know which.
            'publishable' => $critical === [],
            'blocking' => array_map(
                static fn (array $check): string => $check['label'],
                $critical,
            ),
            'checks' => array_map(
                static fn (array $check): array => [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'ok' => $check['ok'],
                    'detail' => $check['detail'],
                    'severity' => $check['severity'],
                ],
                $checks,
            ),
        ];
    }

    /**
     * The numbers beside the checklist: what this article is aiming at, and
     * what it is made of.
     *
     * @return array<string, mixed>
     */
    public function data(ContentItem $item): array
    {
        $body = (string) $item->body_markdown;

        return [
            'target_query' => $item->target_query,
            'topic_volume' => $item->topic_volume,
            'topic_difficulty' => $item->topic_difficulty,
            // Unicode-aware. str_word_count reported a 1,847-word Russian
            // article as 66 words, because its idea of a letter stops at the
            // ASCII range.
            'words' => Words::count(strip_tags((string) $item->body_html)),
            'keywords' => count($item->entity_coverage),
            'images' => $item->assets->count(),
            'internal_links' => count($item->internal_links),
            'external_links' => $this->externalLinks($body),
            'slug' => $item->slug,
            'meta_description' => $item->summary,
            'meta_length' => mb_strlen((string) $item->summary),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, ok: bool, detail: string, weight: int, severity: string}>  $checks
     */
    private function score(array $checks): int
    {
        $total = array_sum(array_column($checks, 'weight'));

        if ($total === 0) {
            return 0;
        }

        $earned = array_sum(array_map(
            static fn (array $check): int => $check['ok'] ? $check['weight'] : 0,
            $checks,
        ));

        return (int) round(($earned / $total) * 100);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string, weight: int, severity: string}
     */
    private function check(
        string $key,
        string $label,
        bool $ok,
        string $detail,
        int $weight = 1,
        string $severity = 'warning',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
            'weight' => $weight,
            // How much a failure matters. `critical` blocks publishing whatever
            // the total says; the rest only move the number. Treating every
            // check as equal made a missing FAQ weigh the same as prose that
            // reads as machine-written.
            'severity' => $severity,
        ];
    }

    /** Markdown links to somewhere that is not this site. */
    private function externalLinks(string $body): int
    {
        preg_match_all('/\]\((https?:\/\/[^)]+)\)/i', $body, $matches);

        return count(array_unique($matches[1]));
    }

    private function blockquotes(string $body): int
    {
        return (int) preg_match_all('/^>\s+\S/m', $body);
    }

    /**
     * Numbers that carry meaning: percentages, money, measurements, years.
     * A bare "3" in "3 rooms" is not a data point, so a digit alone does not
     * count.
     */
    private function figures(string $body): int
    {
        // The units a practical article actually uses. A bare digit is not a
        // data point — "3 rooms" in passing is not the same as "every 3 months".
        return (int) preg_match_all(
            '/\b\d+(?:[.,]\d+)?\s?(?:°c|°f|%|€|\$|£|ml|l\b|g\b|kg|cm|m²|sq ?m|per ?cent|percent|'
            .'minutes?|mins?|hours?|hrs?|days?|weeks?|months?|years?)/iu',
            $body,
        );
    }

    private function imagesWithAlt(ContentItem $item): int
    {
        return $item->assets
            ->filter(static fn (Asset $asset): bool => trim((string) $asset->alt) !== '')
            ->count();
    }

    private function hasSources(ContentItem $item): bool
    {
        $factcheck = $item->factcheck;

        return ($factcheck['passed'] ?? false) === true
            || $this->externalLinks((string) $item->body_markdown) > 0;
    }

    /**
     * A meta description that search engines will show whole: present, and
     * inside the length they truncate at.
     */
    private function metaIsGood(ContentItem $item): bool
    {
        $length = mb_strlen((string) $item->summary);

        return $length >= 50 && $length <= 160;
    }
}
