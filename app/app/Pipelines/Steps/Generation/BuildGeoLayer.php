<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Enums\ContentItemType;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Str;

/**
 * The GEO layer (§5.3, §1's first differentiator).
 *
 * Three products, and only one of them needs a model:
 *
 * - **json_ld** is derived from what the unit already is — its type decides the
 *   schema.org type, and the author, headline and language are facts. Asking a
 *   model to emit structured data it could get wrong, from data we hold, would
 *   be paying for a chance of malformed schema.
 * - **faq_json_ld** does need one: questions a reader actually asks are not
 *   derivable from the body.
 * - **quotable blocks** are extracted from the draft rather than written fresh,
 *   because a quotable block that does not appear in the article is a quote the
 *   article cannot support.
 */
class BuildGeoLayer extends AbstractStep
{
    use ResolvesUnit;

    /** Below this, a paragraph is a fragment; above it, nobody quotes it whole. */
    private const int MIN_QUOTABLE = 120;

    private const int MAX_QUOTABLE = 500;

    public static function key(): string
    {
        return 'build_geo_layer';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteDraft::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $brief = $context->output(CompileBrief::key(), BriefContextPayload::class);
        $draft = $context->output(WriteDraft::key(), DraftPayload::class);

        $faq = $this->faq($context, $draft);

        return StepResult::success(new GeoPayload(
            jsonLd: $this->articleSchema($unit->type, $unit->title, $draft->summary, $brief),
            faqJsonLd: $faq === [] ? [] : $this->faqSchema($faq),
            quotableBlocks: $this->quotable($draft->markdown),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function articleSchema(
        ContentItemType $type,
        string $headline,
        string $summary,
        BriefContextPayload $brief,
    ): array {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $type->schemaType(),
            'headline' => $headline,
            'description' => $summary,
            'inLanguage' => $brief->locale,
        ];

        // E-E-A-T (§5.2). Present whenever the project has a named author, and
        // required before a YMYL unit can generate at all.
        if ($brief->author !== []) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => (string) ($brief->author['name'] ?? ''),
                ...isset($brief->author['title']) ? ['jobTitle' => (string) $brief->author['title']] : [],
            ];
        }

        return $schema;
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faq
     * @return array<string, mixed>
     */
    private function faqSchema(array $faq): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $pair): array => [
                '@type' => 'Question',
                'name' => $pair['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $pair['answer']],
            ], $faq),
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function faq(StepContext $context, DraftPayload $draft): array
    {
        $answer = $context->ask(
            role: 'utility',
            prompt: "Article:\n\n{$draft->markdown}\n\n"
                .'Write the three questions a reader most likely still has, each with a two-sentence answer '
                .'drawn only from the article. Format each as `Q: ...` then `A: ...` on the next line.',
            instructions: 'You write FAQ entries that can be answered from the text in front of you.',
        );

        $faq = [];
        $question = null;

        foreach (preg_split('/\R/', trim($answer->text)) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^Q:\s*(.+)$/u', $line, $m) === 1) {
                $question = trim($m[1]);

                continue;
            }

            if ($question !== null && preg_match('/^A:\s*(.+)$/u', $line, $m) === 1) {
                $faq[] = ['question' => $question, 'answer' => trim($m[1])];
                $question = null;
            }
        }

        return $faq;
    }

    /**
     * Paragraphs that stand on their own.
     *
     * A block is quotable when it can be lifted out of the article and still
     * make sense — so headings, lists and anything that opens with a pronoun or
     * a connective ("This means...", "However...") are out: they are sentences
     * about the paragraph before them.
     *
     * @return list<string>
     */
    private function quotable(string $markdown): array
    {
        $dependent = ['this ', 'that ', 'these ', 'those ', 'it ', 'however', 'therefore', 'so ', 'but ', 'and '];

        $blocks = [];

        foreach (preg_split('/\R{2,}/', trim($markdown)) ?: [] as $block) {
            // Str::squish runs `/u` regexes, and preg_replace returns null
            // rather than the subject when one of them fails — on invalid
            // UTF-8, or on hitting PCRE's backtrack limit, which a long block
            // of non-Latin text reaches sooner because it is more bytes for the
            // same number of characters. Every article here was Latin until
            // this project added Russian, and the first Cyrillic draft killed
            // the step on a TypeError three lines down.
            //
            // A block that cannot be read is a block with no quotable line in
            // it. That is a skip, not a lost article.
            /** @var string|null $squished */
            $squished = Str::squish($block);
            $clean = $squished ?? '';

            if ($clean === '' || str_starts_with($clean, '#') || str_starts_with($clean, '-')) {
                continue;
            }

            if (str_starts_with($clean, 'Summary:')) {
                continue;
            }

            $length = mb_strlen($clean);

            if ($length < self::MIN_QUOTABLE || $length > self::MAX_QUOTABLE) {
                continue;
            }

            $opening = mb_strtolower($clean);

            foreach ($dependent as $prefix) {
                if (str_starts_with($opening, $prefix)) {
                    continue 2;
                }
            }

            $blocks[] = $clean;
        }

        return $blocks;
    }
}
