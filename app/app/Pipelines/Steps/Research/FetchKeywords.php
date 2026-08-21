<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Models\Project;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\Contracts\KeywordSource;
use App\Research\KeywordIdea;
use App\Support\Content\Squish;
use App\Support\Corpus\SiteLibrary;
use App\Support\Corpus\TopicLibrary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ask the keyword source about each of the project's seeds (§4.1).
 *
 * On the expensive queue: it is the only step here that leaves the machine, and
 * a paid API with a per-minute quota belongs in the same pool as model calls
 * rather than in front of every cheap step in the system.
 */
class FetchKeywords extends AbstractStep
{
    public function __construct(
        private readonly KeywordSource $keywords,
        private readonly SiteLibrary $library,
        private readonly TopicLibrary $topics,
    ) {}

    public static function key(): string
    {
        return 'fetch_keywords';
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        // Refreshed here rather than on a schedule of its own: research is the
        // step that decides what a project could write about, and it should be
        // deciding against what the site publishes today.
        $this->library->refresh($context->project);

        // And then read what the pages actually say. Separate from the refresh
        // above because it costs model calls: the refresh is a sitemap and some
        // head tags, this is the evidence corpus the planner writes from, and
        // it belongs at a call site with a run to charge it to. Bounded per
        // pass, so a large site fills in over several weeks rather than in one
        // long crawl.
        $harvested = $this->library->harvest($context->project, $context);

        if ($harvested > 0) {
            $context->remember('site_library.pages_harvested', $harvested);
        }

        $this->topics->index($context->project);

        $seeds = $this->seeds($context);

        if ($seeds === []) {
            throw new TerminalStepFailure(
                'This project has no research seeds. Set `research_seeds` on the project, '
                .'or pass `seeds` to the run — research cannot guess what the business is about.'
            );
        }

        if (! $this->keywords->isConfigured()) {
            throw new TerminalStepFailure(
                "The `{$this->keywords->name()}` keyword source is not configured."
            );
        }

        $limit = (int) config('research.per_seed_limit', 50);
        $found = [];
        $barren = [];

        foreach ($seeds as $seed) {
            $ideas = $this->keywords->matchingTerms(
                $seed,
                $context->project->market,
                $limit,
                // Research runs before any brief exists, so the project's own
                // default is the best answer available. A project publishing in
                // several locales plans one pool and writes from it; splitting
                // the pool per locale is a planning change, not a source one.
                $context->project->default_locale,
            );

            if ($ideas === []) {
                $barren[] = $seed;
            }

            foreach ($ideas as $idea) {
                // Two seeds routinely return the same keyword. Keyed by the
                // keyword itself so the pool holds each one once, keeping
                // whichever reading arrived first.
                $found[$idea->keyword] ??= $idea;
            }
        }

        if ($barren !== []) {
            // Named, because the usual cause is a seed phrased as marketing
            // copy rather than as something anybody types. The source matches
            // by containment: "premium home cleaning Lisbon" contains no
            // search anybody makes, and returns nothing without erroring.
            Log::warning('Some research seeds matched nothing', [
                'project' => $context->project->slug,
                'market' => $context->project->market,
                'seeds' => $barren,
            ]);
        }

        // Per project, falling back to the installation default. What counts
        // as too small depends entirely on the market: 30 searches a month is
        // noise for a national SaaS and a real customer stream for a cleaning
        // business in one city.
        $floor = $context->project->minimum_volume ?? (int) config('research.minimum_volume', 50);
        $ceiling = (int) config('research.maximum_difficulty', 70);

        $brands = $this->competitorBrands($context->project);

        $pool = array_values(array_filter(
            $found,
            fn (KeywordIdea $idea): bool => $idea->volume >= $floor
                && $idea->difficulty <= $ceiling
                && ! $this->isSomebodyElsesBrand($idea->keyword, $brands),
        ));

        // Best opportunity first, and by keyword where two tie — the pool has
        // to be reproducible (exit criterion 1), and PHP's sort is not stable
        // in a way worth relying on across versions.
        usort($pool, static function (KeywordIdea $a, KeywordIdea $b): int {
            return $b->opportunity() <=> $a->opportunity()
                ?: strcmp($a->keyword, $b->keyword);
        });

        // The pool-size guard used to be here and is now in
        // {@see DropIrrelevant}, which is the first step holding both halves of
        // the pool. Judging it here would fail a project whose expansion is
        // thin and whose proposed angles are rich — which is the exact case
        // ProposeAngles was written for.

        return StepResult::success(new KeywordPoolPayload(
            keywords: $pool,
            source: $this->keywords->name(),
        ));
    }

    /**
     * The names of the businesses this project competes with.
     *
     * Taken from the competitor domains the project already stores: the brand
     * is the domain without its suffix, which is what somebody types when they
     * search for that company.
     *
     * @return list<string>
     */
    private function competitorBrands(Project $project): array
    {
        $brands = [];

        foreach ($project->competitors as $domain) {
            $host = Str::of((string) $domain)
                ->lower()
                ->after('//')
                ->before('/')
                ->replaceStart('www.', '')
                ->toString();

            $brand = Str::before($host, '.');

            // Two-letter names are not distinctive enough to filter on without
            // catching ordinary words.
            if (mb_strlen($brand) > 2) {
                $brands[] = $brand;
            }
        }

        return array_values(array_unique($brands));
    }

    /**
     * Whether a keyword is really somebody else's company name.
     *
     * The keyword source returns the long tail around a head term, and for a
     * local service business that tail is full of rivals — this project was
     * about to publish an article targeting `cleann.pt cleaning company in
     * lisbon`, which is a competitor's brand. Writing it means ranking their
     * name on our site, and the click goes to whoever the searcher was looking
     * for in the first place.
     *
     * @param  list<string>  $brands
     */
    private function isSomebodyElsesBrand(string $keyword, array $brands): bool
    {
        if ($brands === []) {
            return false;
        }

        $haystack = ' '.Squish::text(mb_strtolower(str_replace(['.', '-', '_'], ' ', $keyword))).' ';

        foreach ($brands as $brand) {
            if (str_contains($haystack, ' '.$brand.' ')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function seeds(StepContext $context): array
    {
        /** @var list<string> $fromInput */
        $fromInput = $context->get('seeds', []);

        if ($fromInput !== []) {
            return $fromInput;
        }

        return $context->project->research_seeds;
    }
}
