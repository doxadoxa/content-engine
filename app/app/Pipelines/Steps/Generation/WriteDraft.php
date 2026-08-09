<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Content\HouseStyle;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Research\Contracts\KeywordSource;
use App\Research\SerpLength;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The body (§5.1).
 *
 * Leaves an image anchor after each section rather than generating pictures:
 * §5's out-of-scope note keeps the image pipeline in phase 8 and leaves only
 * "the placement anchor in the text" here. The anchors are heading slugs, which
 * is what `assets.anchor` points at.
 */
class WriteDraft extends AbstractStep
{
    use ResolvesUnit;

    public function __construct(
        private readonly HouseStyle $style,
        private readonly KeywordSource $keywords,
        private readonly SerpLength $lengths,
    ) {}

    public static function key(): string
    {
        return 'write_draft';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WriteOutline::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $brief = $context->output(CompileBrief::key(), BriefContextPayload::class);
        $outline = $context->output(WriteOutline::key(), OutlinePayload::class);

        // The brief's locale, not the project's default: the SERP that decides
        // how long this article should be, and which pages it may cite, is the
        // SERP in the language it is being written in.
        $sources = $this->sources($context, $unit, $brief->locale);
        $length = $this->targetLength($context, $unit, $brief->locale);

        // Recorded before the writing, so the score can tell "cited nothing"
        // apart from "had nothing worth citing". A local commercial query
        // returns competitors and nothing else, and declining to link a rival
        // is the right call rather than a shortcoming.
        $unit->forceFill(['offered_sources' => $sources])->save();

        $answer = $context->ask(
            role: 'draft',
            prompt: implode("\n\n", array_values(array_filter([
                "Target query: {$brief->targetQuery}",
                // The planned title is the search query in title case, which
                // is what the planner had before anybody wrote anything. Handed
                // over as "Title:" the writer echoed it back as the H1, so
                // every article was named after its keyword — and in the
                // keyword's language, whatever language the article was in.
                "Working title, for orientation only — write your own: {$unit->title}",
                "Aim for about {$length} words. That is what the pages currently answering this "
                .'search run to; much shorter looks thin beside them and much longer is padding.',
                "Write these sections, in order, as markdown with `##` headings:\n- ".implode("\n- ", $outline->sections),
                $unit->entities === []
                    ? null
                    : 'Name every one of these, each at least once: '.implode(', ', $unit->entities),

                $brief->originalData === []
                    ? 'Do not state prices, figures or specifics you were not given.'
                    : "Use these business facts and do not contradict them:\n".json_encode($brief->originalData),

                // The four things a thin article is missing, asked for by name.
                // Each is fenced against invention, because an article that
                // scores well by making things up is worse than a thin one.
                implode("\n", [
                    'The article must also contain:',
                    // Asked for as *practical* numbers rather than as
                    // "statistics". Told to include statistics and forbidden to
                    // invent, a careful model correctly produces none — and a
                    // reader gets an article with nothing specific in it. How
                    // long a job takes and how often to do it are knowable and
                    // useful; a market-size percentage is neither.
                    '- At least four practical numbers a reader can act on: how long something '
                    .'takes, how often to do it, what temperature or dilution to use, how many '
                    .'of something is typical. Ranges are fine and usually more honest than a '
                    .'single figure. These are ordinary domain knowledge, not research findings. '
                    .'Never invent a statistic, a percentage or a study, and never attribute a '
                    .'number to a source you cannot name.',
                    '- One or two short blockquotes (lines beginning `> `) giving practical '
                    .'guidance in the brand voice. Attribute them to the business itself or to '
                    .'nobody. Never quote a named person, company or publication you cannot '
                    .'verify — an invented expert is worse than no quote.',
                    '- A section naming two to four real limitations: where this does not fit, '
                    .'who should not bother, what it will not solve. Genuine ones — narrowing '
                    .'the ideal case, not false modesty.',
                    $sources === []
                        ? '- No outside links. You cannot check whether a URL exists, and an '
                        .'invented citation is worse than none.'
                        : '- Two or three links to sources, as markdown links, and *only* from '
                        .'the list below. These are real pages that currently rank for this '
                        .'query; nothing else may be linked. Cite one only where it genuinely '
                        .'supports the sentence it is attached to.',
                ]),

                // Under 155 characters, because it is the meta description
                // and search engines cut it at about 160. One that runs to 188
                // is shown truncated, which is worse than a shorter one.
                $sources === []
                    ? null
                    : "Sources you may link to, and no others:\n".implode("\n", array_map(
                        static fn (array $page): string => "- [{$page['title']}]({$page['url']})",
                        $sources,
                    )),

                'Finish with a line beginning `Summary:` containing one sentence a reader could '
                .'quote, under 155 characters including spaces.',
            ]))),
            // The house style goes in the instructions rather than the prompt:
            // it governs how everything is written, not what this particular
            // article is about. See product/humanizated-articles.md.
            instructions: implode("\n\n", [
                $brief->compiledBrief,
                "You are writing the article itself, in {$brief->locale}.",
                'Open with a single # heading that is the article\'s real headline, in that same '
                    .'language. Not the working title and not the search query: something a person '
                    .'would click, naming the specific thing this piece is about. The search query '
                    .'belongs in the prose where it fits, not in the headline.',
                // In the language the article is being written in: the banned
                // words and the punctuation rules both differ by language, and
                // the draft is checked against exactly this list.
                $this->style->instructions($brief->locale),
            ]),
        );

        $markdown = trim($answer->text);

        return StepResult::success(new DraftPayload(
            markdown: $markdown,
            summary: $this->summary($markdown, $unit->title),
            imageAnchors: array_map(
                static fn (string $section): string => Str::slug($section),
                $outline->sections,
            ),
        ));
    }

    /**
     * How long this article should be.
     *
     * From the pages already answering the query where the SERP can be read,
     * and from the project's own setting when it cannot. A fixed number chosen
     * once in a wizard is a guess applied to every topic: "how to clean a
     * marble floor" and "cleaning services in Lisbon" are not the same length
     * of answer.
     *
     * The wizard's `target_words` was stored and read by nothing at all until
     * now, so every article was written to no length guidance whatsoever.
     */
    private function targetLength(StepContext $context, ContentItem $unit, string $locale): int
    {
        $fallback = (int) ($context->project->article_settings['target_words'] ?? 1400);

        $query = (string) ($unit->target_query ?? $unit->title);

        if (trim($query) === '') {
            return $fallback;
        }

        // Kept on the unit once measured. Reading it costs eight page fetches
        // from other people's servers, and regenerating an article — which
        // happens on every refresh and every retry — should not repeat them.
        if ($unit->serp_target_words !== null) {
            return $unit->serp_target_words;
        }

        $measured = $this->lengths->targetFor($query, $context->project->market, $locale);

        if ($measured === null) {
            return $fallback;
        }

        // Bounded either side. A SERP of link pages would ask for four hundred
        // words and a SERP of documentation for eight thousand; neither is an
        // article this engine should be writing.
        $target = max(700, min(4000, $measured));

        $unit->forceFill(['serp_target_words' => $target])->save();

        return $target;
    }

    /**
     * Reference sources, from pages that actually rank.
     *
     * The SERP for a commercial query is competitors, almost by definition —
     * the first draft of this cited two rival cleaning companies, which is a
     * worse outcome than citing nothing. So the results are allow-listed down
     * to the kinds of host a business can point a customer at without sending
     * them to somebody else's booking form: public bodies, standards
     * organisations, encyclopaedias.
     *
     * Often that leaves none, and none is the right answer. An article with no
     * citation is honest; an article citing a competitor is an own goal, and
     * one citing an invented URL is a lie.
     *
     * @return list<array{url: string, title: string}>
     */
    private function sources(StepContext $context, ContentItem $unit, string $locale): array
    {
        $query = (string) ($unit->target_query ?? $unit->title);

        // The entities are the reference-shaped half of the topic — a material,
        // a standard, a process — where the target query is the commercial
        // half. Asking about both is what turns up a public body at all.
        $queries = array_slice([$query, ...$unit->entities], 0, 3);

        $keep = [];
        $seen = [];

        foreach ($queries as $each) {
            try {
                $ranking = $this->keywords->rankingPages((string) $each, $context->project->market, language: $locale);
            } catch (Throwable $e) {
                Log::info('Could not read the ranking pages for citations', [
                    'query' => $each,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($ranking as $page) {
                $host = mb_strtolower((string) parse_url($page['url'], PHP_URL_HOST));

                if ($host === '' || isset($seen[$host]) || ! $this->isReference($host)) {
                    continue;
                }

                $seen[$host] = true;
                $keep[] = $page;

                if (count($keep) >= 4) {
                    return $keep;
                }
            }
        }

        return $keep;
    }

    /**
     * A host worth citing: one that informs rather than sells.
     *
     * Deliberately narrow. Everything outside it might be a competitor, and the
     * cost of being wrong is an article that advertises somebody else.
     */
    private function isReference(string $host): bool
    {
        $patterns = [
            '/\.gov(\.[a-z]{2})?$/',
            '/\.gov\.[a-z]{2}$/',
            '/\.edu(\.[a-z]{2})?$/',
            '/\.ac\.[a-z]{2}$/',
            '/\.europa\.eu$/',
            '/\.int$/',
            '/(^|\.)wikipedia\.org$/',
            '/(^|\.)who\.int$/',
            '/(^|\.)iso\.org$/',
            '/(^|\.)cen\.eu$/',
            '/(^|\.)dgs\.pt$/',
            '/(^|\.)sns\.gov\.pt$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The meta description, and never longer than one. */
    private function summary(string $markdown, string $fallback): string
    {
        if (preg_match('/^Summary:\s*(.+)$/mu', $markdown, $matches) === 1) {
            return $this->withinMetaLength(trim($matches[1]));
        }

        // The first real sentence, or the title. A unit without a summary has
        // no meta description and no line in an llms.txt (§5.3), so something
        // true is better than nothing.
        $firstLine = collect(preg_split('/\R/', $markdown) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->first(static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'));

        return $this->withinMetaLength($firstLine ?? $fallback);
    }

    /**
     * Trimmed at a word, not mid-word.
     *
     * Asking for 155 characters gets one over the line often enough that the
     * limit has to be enforced here as well — search engines truncate at about
     * 160 and a cut-off sentence is what a searcher sees.
     */
    private function withinMetaLength(string $summary): string
    {
        if (mb_strlen($summary) <= 160) {
            return $summary;
        }

        return rtrim(Str::limit($summary, 157, ''), ' ,;:-').'…';
    }
}
