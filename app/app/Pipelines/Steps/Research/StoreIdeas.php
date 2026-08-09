<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Research;

use App\Enums\SearchIntent;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Content\Squish;
use Illuminate\Support\Str;

/**
 * Turn the pool into content units in `idea` (§4.1).
 *
 * This is where exit criterion 1's "a repeat in the same week does not breed
 * duplicates" is kept, and it is kept by asking the corpus rather than by
 * remembering the last run: a keyword the project already has a unit for — in
 * any state, planned or published or still an idea — is skipped. That makes the
 * step idempotent against re-runs, against a second seed producing the same
 * keyword, and against a human running it twice, without any of them being
 * special cases.
 */
class StoreIdeas extends AbstractStep
{
    public static function key(): string
    {
        return 'store_ideas';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ClusterByIntent::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $pool = $context->output(ClusterByIntent::key(), KeywordPoolPayload::class);

        // One query rather than one per keyword: a weekly pool is hundreds of
        // rows and the corpus is thousands.
        //
        // Articles only. A social post carries a `target_query` too, and a post
        // asking about a keyword is the §1.3 reason to write the article about
        // it — counting it as "already known" would let the post delete the
        // idea before a planner ever saw it.
        $known = ContentItem::query()
            ->roots()
            ->whereNotNull('target_query')
            ->pluck('target_query')
            ->map(static fn (mixed $query): string => self::normalise((string) $query))
            ->flip();

        $created = [];
        $skipped = [];

        foreach ($pool->keywords as $idea) {
            $normalised = self::normalise($idea->keyword);

            if ($known->has($normalised)) {
                $skipped[] = $idea->keyword;

                continue;
            }

            $intent = SearchIntent::from($pool->intents[$idea->keyword] ?? SearchIntent::Informational->value);

            // No `state` here: it is not fillable, on purpose. A unit starts
            // at `idea` from the model's own defaults, and the only thing
            // allowed to move it afterwards is the state machine.
            ContentItem::query()->create([
                // The language the keyword was measured in, not the project's
                // default. A keyword lives in a language and an article that is
                // not in that language cannot rank for it — this line read
                // `default_locale` and produced Portuguese search queries with
                // English articles under them.
                'locale' => $idea->language ?? $context->project->default_locale,
                'type' => $intent->suggestedType(),
                'slug' => $this->slug($idea->keyword),
                // The keyword as it was searched, not Str::headline() of it. A
                // title-cased query is a manufactured title: it looks like an
                // editorial decision nobody made, and it carries the keyword's
                // language whatever language the article ends up in. The writer
                // names the article at the end of generation; until then the
                // honest label is the query itself.
                'title' => $idea->keyword,
                'target_query' => $idea->keyword,
                'entities' => $idea->entities,
                'topic_difficulty' => $idea->difficulty,
                'topic_volume' => $idea->volume,
                // The monthly curve, stored beside the single figure it is the
                // shape of. §5 plans the seasonal band four to six weeks ahead
                // of a peak, and research is the only place that ever sees it —
                // asking the vendor again in November to find out that November
                // was the peak is the integration the spec says this is not.
                'monthly_volumes' => $idea->volumeByMonth,
                'intent' => $intent->value,
                'cluster' => $pool->clusters[$idea->keyword] ?? $idea->keyword,
                'planned_derivatives' => [],
            ]);

            // Added to the seen set as well as the database, so two spellings
            // of one keyword inside a single pool cannot both get through. The
            // value is never read — flip() built this as keyword => position,
            // and matching that type keeps the collection homogeneous.
            $known->put($normalised, 0);
            $created[] = $idea->keyword;
        }

        $context->remember('research.created', count($created));

        return StepResult::success(new StoredIdeasPayload($created, $skipped));
    }

    /**
     * Compare topics, not strings: "How To Clean Windows" and "how to clean
     * windows" are the same article, and researching twice should not produce
     * both.
     */
    public static function normalise(string $keyword): string
    {
        return Squish::text(mb_strtolower($keyword));
    }

    /**
     * Unique per project and locale, because the database says so. The suffix
     * is only reached when two different keywords slug to the same thing.
     */
    private function slug(string $keyword): string
    {
        $base = Str::slug($keyword) ?: 'idea';
        $slug = $base;
        $suffix = 2;

        while (ContentItem::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
