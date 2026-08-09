<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Models\BrandBrief;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\KeywordIdea;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drop the keywords that are not about this business (§4.1).
 *
 * A keyword source matches strings, not meaning. Seed a cleaning company in
 * Lisbon with "limpeza profunda lisboa" and it returns "limpeza de pele
 * profunda lisboa" — deep *skin* cleansing, a facial treatment — with a real
 * search volume and no hint that it belongs to a different industry. Without
 * this step that becomes a planned article, then a published one, and the
 * project is writing about facials.
 *
 * One model call for the whole pool rather than one per keyword: the judgement
 * is easier with the list in front of it, and the alternative is a call per
 * keyword on a pool of several hundred.
 *
 * Fails open, in every sense: a model that answers with nonsense, rejects
 * everything, or does not answer at all leaves the pool as it was. Writing
 * about the wrong topic costs one article; an empty pool costs the month, and
 * research managed without a model call at all until this step existed.
 */
class DropIrrelevant extends AbstractStep
{
    public static function key(): string
    {
        return 'drop_irrelevant';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [FetchKeywords::key(), ProposeAngles::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $pool = $this->merged($context);

        $this->assertBigEnoughToPlanFrom($context, $pool);

        if ($pool->keywords === []) {
            return StepResult::skip('There is nothing in the pool to judge.');
        }

        $brief = BrandBrief::activeFor($context->project);

        if ($brief === null) {
            // Nothing to judge against. Guessing what the business is from its
            // own keywords would only ever agree with them.
            return StepResult::success($pool);
        }

        $keywords = array_map(
            static fn (KeywordIdea $idea): string => $idea->keyword,
            $pool->keywords,
        );

        try {
            $answer = $context->ask(
                'utility',
                implode("\n", [
                    'The business:',
                    $brief->compileToPrompt(),
                    '',
                    'Candidate search terms:',
                    ...array_map(static fn (string $k): string => "- {$k}", $keywords),
                ]),
                implode("\n", [
                    'You decide which search terms belong to this business.',
                    'A term belongs if someone searching it could plausibly become a customer,',
                    'or is asking a question this business is qualified to answer.',
                    '',
                    'A term does NOT belong if:',
                    '- it is a different industry that happens to share a word;',
                    // Both of these reached a live calendar. A keyword source
                    // returns the long tail around a head term, and for a local
                    // service business that tail is full of rivals and of
                    // neighbouring cities — "superlimpa empresa de limpeza
                    // lisboa" and "limpeza pós obra porto" were both planned as
                    // articles for a Lisbon company. Writing the first ranks a
                    // competitor's name on our own site and sends the click to
                    // whoever the searcher was looking for; writing the second
                    // earns traffic from a city this business cannot serve.
                    '- it names another company. Any brand or trading name that is not this business,',
                    '  including ones not listed above as competitors;',
                    '- it names a place this business does not serve.',
                    '',
                    'Answer with the terms that do NOT belong, one per line, verbatim, nothing else.',
                    'If they all belong, answer exactly: NONE',
                ]),
            );
        } catch (Throwable $e) {
            // Failing open means exactly this, and the docblock above would be
            // a lie without it: research used to need no model at all, and a
            // provider outage must not take the whole pipeline down over a
            // filter. One off-topic keyword costs one article; a failed
            // research run costs the month.
            Log::warning('Relevance could not be judged; keeping the pool as it is', [
                'project' => $context->project->slug,
                'reason' => $e->getMessage(),
            ]);

            return StepResult::success($pool);
        }

        $rejected = $this->rejected($answer->text, $keywords);

        if ($rejected === []) {
            return StepResult::success($pool);
        }

        $kept = array_values(array_filter(
            $pool->keywords,
            static fn (KeywordIdea $idea): bool => ! in_array(
                Str::lower($idea->keyword),
                $rejected,
                true,
            ),
        ));

        if ($kept === []) {
            // Everything rejected is the model having misread the brief, not a
            // business with no keywords. Failing open here costs one bad
            // article; failing closed costs the whole month.
            Log::warning('Relevance judged the entire pool off-topic; keeping it', [
                'project' => $context->project->slug,
                'keywords' => count($keywords),
            ]);

            return StepResult::success($pool);
        }

        Log::info('Dropped keywords that are not about this business', [
            'project' => $context->project->slug,
            'dropped' => $rejected,
        ]);

        return StepResult::success(new KeywordPoolPayload(
            keywords: $kept,
            source: $pool->source,
        ));
    }

    /**
     * Below this the pool cannot fill a month.
     *
     * Checked here rather than in {@see FetchKeywords}, where it used to live,
     * because that step only ever sees the expanded half. A project whose seeds
     * expand thinly but whose proposed angles are rich would have failed on the
     * count of one half while the other sat unread — and that is the exact case
     * {@see ProposeAngles} exists for.
     *
     * Before the model call rather than after: a pool too thin to plan from is
     * too thin whatever the relevance filter thinks of it, and this way it
     * fails without spending anything.
     */
    private function assertBigEnoughToPlanFrom(StepContext $context, KeywordPoolPayload $pool): void
    {
        $minimum = (int) config('research.minimum_pool', 3);

        if (count($pool->keywords) >= $minimum) {
            return;
        }

        $seeds = $context->project->research_seeds;

        // Terminal, and loud. Succeeding here produces a plan of two articles
        // and a dashboard that looks broken for a month, with the cause four
        // steps upstream — where the operator will never look.
        throw new TerminalStepFailure(sprintf(
            'Only %d keyword%s survived research for %s, from %d seed%s (%s) and the angles proposed '
            .'from the brief. The seeds are probably phrased as marketing copy rather than as '
            .'searches: expansion matches by containment, so short terms like "cleaning lisbon" find '
            .'a long tail where "premium home cleaning Lisbon" finds nothing. They should also be in '
            .'the language the market is searched in. Edit the research seeds — or lower the minimum '
            .'search volume, which is set for a national market and is usually too high for a local '
            .'one — and run this again.',
            count($pool->keywords),
            count($pool->keywords) === 1 ? '' : 's',
            $context->project->market,
            count($seeds),
            count($seeds) === 1 ? '' : 's',
            implode(', ', array_slice($seeds, 0, 8)),
        ));
    }

    /**
     * Both halves of the pool, as one.
     *
     * Expansion and proposal answer different questions and either can skip on
     * its own — an unconfigured source, a project with no brief — so a skip
     * releases this step with no payload rather than failing it. Keyed by the
     * keyword itself because the two halves overlap by design: a proposed
     * angle that the expander also found is one idea, and counting it twice
     * would weight the month toward whatever both happened to agree on.
     */
    private function merged(StepContext $context): KeywordPoolPayload
    {
        $found = [];
        $sources = [];

        foreach ([FetchKeywords::key(), ProposeAngles::key()] as $step) {
            if (! $context->hasOutput($step)) {
                continue;
            }

            $half = $context->output($step, KeywordPoolPayload::class);

            if ($half->keywords === []) {
                continue;
            }

            $sources[] = $half->source;

            foreach ($half->keywords as $idea) {
                // First wins, and expansion is asked first, so a keyword the
                // vendor measured through its own index keeps that reading
                // rather than the one taken of a phrase we made up.
                $found[$idea->keyword] ??= $idea;
            }
        }

        return new KeywordPoolPayload(
            array_values($found),
            source: implode('+', array_filter($sources)),
        );
    }

    /**
     * Only terms the model was actually shown.
     *
     * Matched against the candidate list rather than trusted as given: a model
     * that paraphrases, translates or invents a term would otherwise drop
     * nothing while appearing to work.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function rejected(string $answer, array $keywords): array
    {
        $trimmed = trim($answer);

        if ($trimmed === '' || Str::upper($trimmed) === 'NONE') {
            return [];
        }

        $offered = [];

        foreach ($keywords as $keyword) {
            $offered[Str::lower($keyword)] = true;
        }

        $rejected = [];

        foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
            $line = Str::lower(trim(ltrim(trim($line), '-• ')));

            if ($line !== '' && isset($offered[$line])) {
                $rejected[$line] = true;
            }
        }

        return array_keys($rejected);
    }
}
