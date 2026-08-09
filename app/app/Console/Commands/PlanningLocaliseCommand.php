<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Contracts\ModelGateway;
use App\Ai\UnmeteredSession;
use App\Content\SubjectLocaliser;
use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Project;
use App\Pipelines\Steps\Planning\LocaliseVariants;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * `php artisan planning:localise` — say the rows planned before we knew better
 * in their own language.
 *
 * {@see LocaliseVariants} fixes this as a month is
 * planned, and only as a month is planned. Every locale row already on a
 * calendar was created by copying its source unit and still holds that unit's
 * title and search phrase, so generation will keep reading `limpeza pós-obra` /
 * `Language: ru` and keep producing half-Portuguese Russian articles until
 * something goes back over them. This is that something, and it is expected to
 * be run once per installation and then never again.
 *
 * Only rows that are still `idea`. A row that has been written has a body, a
 * slug somebody may have followed and possibly an approval, and retitling it
 * underneath all three is not a backfill — it is an edit, and it belongs to
 * whoever wrote it.
 */
class PlanningLocaliseCommand extends Command
{
    protected $signature = 'planning:localise
        {--project= : Only this project, by slug or id}
        {--from= : Only units scheduled on or after this date}
        {--dry : List what would change, change nothing}';

    protected $description = 'Give already-planned locale rows a title and search phrase in their own language';

    public function handle(CurrentProject $current, SubjectLocaliser $localiser, ModelGateway $gateway): int
    {
        $handle = $this->option('project');

        $projects = Project::query()
            ->when(
                is_string($handle) && $handle !== '',
                fn ($query) => $query->where(fn ($q) => $q->where('slug', $handle)->orWhere('id', $handle)),
            )
            ->get();

        if ($projects->isEmpty()) {
            $this->components->error('No project with that slug or id.');

            return self::FAILURE;
        }

        $from = is_string($this->option('from')) && $this->option('from') !== ''
            ? Carbon::parse((string) $this->option('from'))
            : null;

        $session = new UnmeteredSession($gateway);
        $changed = 0;

        foreach ($projects as $project) {
            $changed += $current->run($project, function () use ($project, $localiser, $session, $from): int {
                return $this->localiseProject($project, $localiser, $session, $from);
            });
        }

        $this->components->info(
            $this->option('dry')
                ? "{$changed} locale row(s) would be localised."
                : "Localised {$changed} locale row(s)."
        );

        return self::SUCCESS;
    }

    private function localiseProject(
        Project $project,
        SubjectLocaliser $localiser,
        UnmeteredSession $session,
        ?Carbon $from,
    ): int {
        $changed = 0;

        foreach ($this->untranslated($from) as $sourceId => $variants) {
            $source = ContentItem::query()->find($sourceId);

            if ($source === null) {
                continue;
            }

            /** @var list<string> $locales */
            $locales = array_values(array_unique(array_map(
                static fn (ContentItem $variant): string => $variant->locale,
                $variants,
            )));

            $this->line("  {$project->slug}: “{$source->title}” → ".implode(', ', $locales));

            if ($this->option('dry')) {
                $changed += count($variants);

                continue;
            }

            $said = $localiser->for($session, $source, $locales);

            foreach ($variants as $variant) {
                $line = $said[$variant->locale] ?? null;

                if ($line === null) {
                    $this->components->warn("    {$variant->locale} came back unusable and was left alone");

                    continue;
                }

                $variant->forceFill([
                    'title' => $line['title'],
                    'target_query' => $line['query'],
                    'slug' => $localiser->slugFor($variant, $line['title']),
                    // Cleared for the same reason the planner no longer copies
                    // them: they are the source market's figures, and this row
                    // is not in that market. See
                    // `product/native-keywords-per-locale.md`.
                    'topic_volume' => null,
                    'topic_difficulty' => null,
                ])->save();

                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Rows that are still saying what another language said, under the row they
     * were copied from.
     *
     * The source is the oldest row of its locale group, and that is a fact
     * about how they are made rather than a guess: research creates the unit,
     * and planning copies it into the other locales weeks later, so a ULID
     * comparison is the creation order. Everything that looks like a property
     * of the source — carrying search volume, being in the project's default
     * language — is true of the copies too, because copying is exactly what
     * went wrong.
     *
     * A copy is then any newer sibling still repeating the source's
     * `target_query` character for character. Matched on the query rather than
     * the title because the title is what an operator may have edited by hand,
     * and the query is what generation actually reads.
     *
     * @return array<string, list<ContentItem>> source id => its untranslated copies
     */
    private function untranslated(?Carbon $from): array
    {
        $groups = ContentItem::query()
            ->roots()
            ->inState(ContentItemState::Idea)
            ->whereNotNull('target_query')
            ->when($from !== null, fn ($q) => $q->where('scheduled_for', '>=', $from->toDateString()))
            ->orderBy('id')
            ->get()
            ->groupBy('locale_group_id');

        $found = [];

        foreach ($groups as $group) {
            /** @var ContentItem $source */
            $source = $group->first();

            /** @var list<ContentItem> $copies */
            $copies = array_values($group
                ->slice(1)
                ->filter(static fn (ContentItem $row): bool => $row->locale !== $source->locale
                    && $row->target_query === $source->target_query)
                ->all());

            if ($copies !== []) {
                $found[(string) $source->getKey()] = $copies;
            }
        }

        return $found;
    }
}
