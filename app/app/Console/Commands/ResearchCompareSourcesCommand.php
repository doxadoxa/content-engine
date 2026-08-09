<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Research\AhrefsKeywordSource;
use App\Research\Contracts\KeywordSource;
use App\Research\DataForSeoKeywordSource;
use App\Research\KeywordIdea;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan research:compare-sources` — the tool that finishes the switch.
 *
 * `minimum_volume` and `maximum_difficulty` decide which keywords become
 * articles, and they were set against Ahrefs numbers. DataForSEO's volumes come
 * from Google Ads, which merges close variants and rounds into buckets, and its
 * difficulty is a different model entirely. Carrying the thresholds over
 * unchanged would be a guess wearing a measurement's clothes — and guessing
 * thresholds has already been wrong twice on this project, once for topic
 * distance against the site and once between two ideas in the same month. Both
 * times the measurement disagreed with the guess.
 *
 * So this prints the distributions and the operator sets the numbers from them.
 * It calls both sources on the project's real seeds, because the question is
 * not "what does this vendor return" but "what does it return for this
 * business, in this country, in this language".
 *
 * Costs real money on both accounts. It is a command rather than a scheduled
 * job for that reason: nobody should discover they ran it nightly.
 */
class ResearchCompareSourcesCommand extends Command
{
    protected $signature = 'research:compare-sources
        {--project= : Slug of the project whose seeds to use; defaults to the first onboarded one}
        {--seeds=3 : How many of its seeds to try}
        {--limit=50 : Keywords per seed, matching research.per_seed_limit}';

    protected $description = 'Compare keyword sources on a project\'s own seeds, to set the pool thresholds from measurement';

    public function handle(): int
    {
        $project = $this->option('project')
            ? Project::query()->where('slug', $this->option('project'))->first()
            : Project::query()->whereNotNull('onboarded_at')->orderBy('created_at')->first();

        if (! $project instanceof Project) {
            $this->error('No project to measure against. Onboard one, or pass --project=<slug>.');

            return self::FAILURE;
        }

        $seeds = array_slice($project->research_seeds, 0, max(1, (int) $this->option('seeds')));

        if ($seeds === []) {
            $this->error("Project {$project->slug} has no research seeds to measure with.");

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $this->line("project <info>{$project->slug}</info>  market <info>{$project->market}</info>  locale <info>{$project->default_locale}</info>");
        $this->newLine();

        $totals = [];

        foreach ($seeds as $seed) {
            $this->line("<comment>seed:</comment> {$seed}");

            foreach ($this->sources() as $label => $resolve) {
                try {
                    $ideas = $resolve()->matchingTerms($seed, $project->market, $limit, $project->default_locale);
                } catch (Throwable $e) {
                    $this->line(sprintf('  %-22s <error>%s</error>', $label, $e->getMessage()));

                    continue;
                }

                $this->line('  '.$this->summarise($label, $ideas));

                foreach ($ideas as $idea) {
                    $totals[$label][] = $idea;
                }
            }

            $this->newLine();
        }

        $this->line('<comment>across all seeds, deduplicated — this is what the pool would hold</comment>');

        foreach ($totals as $label => $ideas) {
            $unique = [];

            foreach ($ideas as $idea) {
                $unique[$idea->keyword] ??= $idea;
            }

            $this->line('  '.$this->summarise($label, array_values($unique)));
        }

        $this->newLine();
        $this->line(sprintf(
            'Currently: minimum_volume=%d maximum_difficulty=%d minimum_pool=%d',
            (int) config('research.minimum_volume'),
            (int) config('research.maximum_difficulty'),
            (int) config('research.minimum_pool'),
        ));
        $this->line('Set those from the columns above, not from the vendor\'s documentation.');

        return self::SUCCESS;
    }

    /**
     * Resolved lazily so an unconfigured vendor costs a line of output rather
     * than the whole comparison.
     *
     * @return array<string, callable(): KeywordSource>
     */
    private function sources(): array
    {
        return [
            'dataforseo/suggestions' => function (): KeywordSource {
                config()->set('research.dataforseo.keyword_endpoint', 'suggestions');

                return app(DataForSeoKeywordSource::class);
            },
            'dataforseo/ideas' => function (): KeywordSource {
                config()->set('research.dataforseo.keyword_endpoint', 'ideas');

                return app(DataForSeoKeywordSource::class);
            },
            'ahrefs' => fn (): KeywordSource => app(AhrefsKeywordSource::class),
        ];
    }

    /**
     * @param  list<KeywordIdea>  $ideas
     */
    private function summarise(string $label, array $ideas): string
    {
        if ($ideas === []) {
            return sprintf('%-22s <error>nothing</error>', $label);
        }

        $volumes = array_map(static fn (KeywordIdea $i): int => $i->volume, $ideas);
        $difficulties = array_map(static fn (KeywordIdea $i): int => $i->difficulty, $ideas);

        sort($volumes);
        sort($difficulties);

        $count = count($ideas);
        $clearing = static fn (int $floor): int => count(array_filter($volumes, static fn (int $v): bool => $v >= $floor));

        // The three columns that matter. `n` is what the vendor found, the
        // `>=` columns are how many survive each candidate floor, and the
        // difficulty median tells you whether the vendor measured difficulty at
        // all — 50 is this codebase's "not measured", so a median of exactly 50
        // means the difficulty filter has nothing to work with.
        return sprintf(
            '%-22s n=%-4d vol med=%-6d max=%-7d  >=50:%-4d >=20:%-4d >=10:%-4d  kd med=%-4d max=%d',
            $label,
            $count,
            $volumes[intdiv($count, 2)],
            $volumes[$count - 1],
            $clearing(50),
            $clearing(20),
            $clearing(10),
            $difficulties[intdiv($count, 2)],
            $difficulties[$count - 1],
        );
    }
}
