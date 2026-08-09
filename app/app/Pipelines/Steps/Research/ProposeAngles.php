<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Models\BrandBrief;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Research\Contracts\KeywordSource;
use App\Research\KeywordIdea;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Topics the business would recognise, checked against real demand (§4.1).
 *
 * The step this pipeline was missing. {@see FetchKeywords} expands seed phrases,
 * and expansion returns text *containing* the seed — so a project seeded with
 * "cleaning lisbon" gets back "lisbon cleaning", "cleaning services lisbon",
 * "cleaning company lisbon". Measured on this project: sixteen ideas, nine of
 * them the same phrase reworded, and the deduplication correctly left six for a
 * month that wanted thirty-one. The dedup was not the problem; it was the only
 * part working, and what it was reporting was that the pool held one idea.
 *
 * A phrase expander cannot propose "rejunte encardido" — grimy grout — from a
 * seed about cleaning, and it certainly cannot propose "nuki vs yale", which is
 * a topic only somebody who knows the business handles clients' keys would
 * think of. Those come from the *offer*: what the company does, who it serves,
 * what goes wrong, and what a customer worries about before paying.
 *
 * So this proposes from the brief and then asks the keyword source whether
 * anyone searches for them. Propose, then check — the reverse of expand, then
 * filter. A proposal nobody searches for is dropped by measurement rather than
 * by taste, and the vendor omitting a phrase entirely is the strongest possible
 * "you made that up".
 *
 * Runs beside {@see FetchKeywords} rather than after it. They answer different
 * questions and neither needs the other; {@see DropIrrelevant} merges them.
 */
class ProposeAngles extends AbstractStep
{
    /**
     * The six shapes a service business's topics come in.
     *
     * Written out rather than left to the model's judgement because "suggest
     * article topics" reliably returns the head terms we already have. Each
     * line is a different place to look, and the mix is what stops a month
     * being ten ways of saying "cleaning services".
     */
    private const array ANGLES = [
        1 => 'a specific problem, stain, smell or damage somebody wants gone',
        2 => 'a specific object, surface or room and how it is cleaned or maintained',
        3 => 'a customer this business serves that others do not serve well',
        4 => 'how the service itself works — contracts, reports, scheduling, access, staffing, trust',
        5 => 'what it costs, and how to judge whether a quote is fair',
        6 => 'one concrete thing against another concrete thing, named, that a buyer compares',
    ];

    /**
     * The angles a keyword tool structurally cannot see, and so the only ones
     * allowed through without a search volume behind them.
     *
     * The distinction matters because "unpriced" has two causes and they are
     * not equally forgivable. A stain or an object that nobody can price is
     * probably a stain nobody has — those angles describe things people type,
     * and Google Ads knows what people type. A customer segment, a question
     * about how the service is run, or a comparison between two named products
     * is a topic somebody has to *know the business* to think of, and the deep
     * tail of those is exactly where a keyword database has nothing.
     *
     * Without this split the allowance was a hole: an invented phrase is
     * unpriced too, and letting every unpriced proposal through published
     * whatever the model felt like inventing.
     */
    private const array UNPRICEABLE_ANGLES = [3, 4, 6];

    public function __construct(private readonly KeywordSource $keywords) {}

    public static function key(): string
    {
        return 'propose_angles';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $this->keywords->isConfigured()) {
            return StepResult::skip("The `{$this->keywords->name()}` keyword source is not configured.");
        }

        $brief = BrandBrief::activeFor($context->project);

        if ($brief === null) {
            // Without a brief there is nothing to propose *from*, and inventing
            // topics out of the project's own keywords would only ever agree
            // with the keywords.
            return StepResult::skip('This project has no brand brief to propose topics from.');
        }

        $wanted = max(1, (int) $context->get('angles', config('research.angles_per_run', 60)));

        try {
            $candidates = $this->propose($context, $brief, $wanted);
        } catch (Throwable $e) {
            // Fails open, like the relevance filter beside it and for the same
            // reason. Research managed without a model call at all until these
            // steps existed; a provider outage must not now cost the month over
            // a step that only ever *adds* to the pool.
            Log::warning('Could not propose topics from the brief', [
                'project' => $context->project->slug,
                'reason' => $e->getMessage(),
            ]);

            return StepResult::skip('The model could not be reached to propose topics.');
        }

        if ($candidates === []) {
            return StepResult::skip('No topics were proposed.');
        }

        $language = $this->language($context);

        try {
            $measured = $this->keywords->measure(array_keys($candidates), $context->project->market, $language);
        } catch (Throwable $e) {
            // Same reasoning one layer down. Expansion has already filled the
            // pool by the time this runs; losing the proposed half is worse
            // than the expanded half but far better than losing both.
            Log::warning('Could not measure the proposed topics', [
                'project' => $context->project->slug,
                'proposed' => count($candidates),
                'reason' => $e->getMessage(),
            ]);

            return StepResult::skip('The keyword source could not measure the proposed topics.');
        }

        $floor = $context->project->minimum_volume ?? (int) config('research.minimum_volume', 50);

        $kept = array_values(array_filter(
            $measured,
            static fn (KeywordIdea $idea): bool => $idea->volume >= $floor,
        ));

        // Then a few that nobody could price at all.
        //
        // Google Ads returns null rather than a figure for the deep long tail,
        // and measured on a competitor's own published calendar that is sixteen
        // of twenty topics — including every one that made the calendar worth
        // copying. Dropping all of them would leave exactly the head terms
        // expansion already finds, which is the thing this step exists to stop.
        //
        // Volume 0 is honest here rather than pessimistic: it is unknown, and
        // it sorts these behind everything measured, so they fill the tail of a
        // month rather than the head.
        $priced = array_map(static fn (KeywordIdea $idea): string => $idea->keyword, $measured);
        $allowance = max(0, (int) config('research.unmeasured_angles_kept', 10));

        $unpriced = array_values(array_diff(
            array_keys(array_filter(
                $candidates,
                static fn (int $angle): bool => in_array($angle, self::UNPRICEABLE_ANGLES, true),
            )),
            $priced,
        ));

        foreach (array_slice($unpriced, 0, $allowance) as $keyword) {
            // The language it was proposed in, not the project's default. These
            // are the only ideas here the vendor never answered about, so
            // nothing else can tell downstream what language they are in — and
            // without it a Portuguese topic is stamped English and written in
            // English, which is the whole bug this release is about.
            $kept[] = new KeywordIdea($keyword, volume: 0, difficulty: 50, language: $language);
        }

        // The three numbers that say whether proposing is working. Proposed but
        // unmeasured means the vendor has never seen the phrase — usually an
        // invented one; measured but under the floor means it is real and too
        // small. They call for opposite fixes, and a single "kept 12" hides
        // which one is happening.
        $context->remember('research.angles_proposed', count($candidates));
        $context->remember('research.angles_measured', count($measured));
        $context->remember('research.angles_kept', count($kept));
        $context->remember('research.angles_unpriced_kept', min(count($unpriced), $allowance));

        if ($kept === []) {
            // The two ways this happens need opposite fixes, and the numbers
            // tell them apart. Nothing measured at all almost always means the
            // proposals were written in a language the market has no keyword
            // index for — DataForSEO lists Portugal as `pt` alone, so a project
            // publishing in English gets sixty perfectly good English phrases
            // and zero readings of them. Measured but all under the floor is
            // the ordinary case of a niche too small.
            $reason = $measured === []
                ? sprintf(
                    'None of the %d topics proposed in %s were recognised in %s. The usual cause is '
                    .'that the market has no keyword index for that language, so the phrases are '
                    .'fine and unmeasurable. Check the project locale against the market.',
                    count($candidates),
                    $language,
                    $context->project->market,
                )
                : sprintf(
                    'All %d measured topics fell under the volume floor of %d. Lower the project '
                    .'minimum volume if this is a small local market.',
                    count($measured),
                    $floor,
                );

            Log::info('No proposed angle survived', [
                'project' => $context->project->slug,
                'proposed' => count($candidates),
                'measured' => count($measured),
                'language' => $language,
                'floor' => $floor,
            ]);

            return StepResult::skip($reason);
        }

        return StepResult::success(new KeywordPoolPayload($kept, source: 'proposed'));
    }

    /**
     * @return array<string, int> query => the angle it came from
     */
    private function propose(StepContext $context, BrandBrief $brief, int $wanted): array
    {
        $project = $context->project;
        $language = $this->language($context);

        $answer = $context->ask(
            'utility',
            implode("\n", array_filter([
                "The business: {$project->name}, selling in {$project->market}.",
                "Positioning: {$brief->positioning}",
                "Audience: {$brief->audience}",
                $project->original_data === []
                    ? null
                    : 'Facts about how it operates: '.json_encode($project->original_data, JSON_UNESCAPED_UNICODE),
                '',
                sprintf('Give %d search queries, spread evenly across these angles:', $wanted),
                ...array_map(
                    static fn (string $angle, int $i): string => sprintf('%d. %s', $i + 1, $angle),
                    self::ANGLES,
                    array_keys(self::ANGLES),
                ),
            ])),
            implode("\n", [
                'You list the things a real customer types into a search engine before they buy a '
                    .'service, or while they are trying to solve the problem themselves. One per line, '
                    .'ending with the number of the angle it came from:',
                'QUERY: the search query | 3',
                '',
                "Write every query in {$language}, phrased as somebody who speaks it would type it — "
                    .'lowercase, no punctuation, no marketing words.',
                'Short. Two to five words is what people type; a sentence is not a search.',
                'Be specific. "cleaning services" is useless because it is what everyone writes; '
                    .'the name of a stain, a material, a room, a document or a brand is not.',
                'Never include the name of the business itself.',
                'Do not repeat the same idea in different words — each line must be a different '
                    .'thing to write about, not a different way of saying one thing.',
                'Output nothing but QUERY: lines.',
            ]),
        );

        $candidates = [];

        foreach (preg_split('/\R/', trim($answer->text)) ?: [] as $line) {
            if (preg_match('/^QUERY:\s*(.+)$/ui', trim($line), $matches) !== 1) {
                continue;
            }

            $parts = array_map(trim(...), explode('|', $matches[1]));
            $query = mb_strtolower($parts[0]);

            // The measuring endpoint takes at most ten words and eighty
            // characters. Anything longer is a sentence, and a sentence sent in
            // a batch is rejected for the whole batch rather than for itself.
            if ($query === '' || isset($candidates[$query]) || mb_strlen($query) > 80) {
                continue;
            }

            $angle = (int) ($parts[1] ?? 0);

            // An unlabelled proposal is treated as one of the priceable angles,
            // which is the cautious reading: it gets no allowance and has to
            // earn its place with a real search volume.
            $candidates[$query] = isset(self::ANGLES[$angle]) ? $angle : 1;

            if (count($candidates) >= $wanted) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * The language to propose in.
     *
     * The language the *market* can be measured in, preferring the project's
     * own where the two agree. This was the project's locale on the first
     * version, which is the more intuitive answer and produced nothing: sixty
     * English topics for a Portuguese market, zero recognised, because
     * DataForSEO has keyword data for Portugal in `pt` alone.
     *
     * There is a real tension underneath and it is the operator's to resolve
     * rather than the code's: a project writing English articles about
     * Portuguese queries will not rank for them. What this step can do is
     * propose topics that can be checked at all, and say which language it
     * used, so the mismatch is visible instead of showing up as an empty pool.
     */
    private function language(StepContext $context): string
    {
        $locale = $context->project->default_locale;

        return $this->keywords->languageFor($context->project->market, $locale) ?? $locale;
    }
}
