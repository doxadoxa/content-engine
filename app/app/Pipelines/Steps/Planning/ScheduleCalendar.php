<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Enums\ContentItemType;
use App\Enums\ContentPlanStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fan-in: build the month (§4.2).
 *
 * Reads both branches — which units, and what each one is — and writes a
 * `ContentPlan` in `draft` whose units are still `idea`. That combination is
 * exit criterion 2, and it is deliberate: a plan is a proposal, and nothing
 * about it is committed until a human approves it (phase 7, `plan:approve`
 * until then).
 *
 * Locale variants are created here rather than in generation, because §2 makes
 * a locale a unit of its own rather than a translation — the Portuguese article
 * is planned, dated and costed like any other, not derived from the English one
 * afterwards.
 *
 * `weekly_target` is counted in *units*, not in rows: a bilingual project on
 * two a week publishes two things a week in each language, not one in each.
 * That reading matches what an operator means by "how often do we publish", and
 * it is the one the cost report has to be read against.
 */
class ScheduleCalendar extends AbstractStep
{
    public static function key(): string
    {
        return 'schedule_calendar';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [SelectTopics::key(), TypeAndFlagUnits::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $selection = $context->output(SelectTopics::key(), SelectionPayload::class);
        $typing = $context->output(TypeAndFlagUnits::key(), TypingPayload::class);

        $window = PlanningWindow::resolve($context->get('month'));
        $month = $window->month;
        $dates = $window->dates(count($selection->selected));
        $needData = array_flip($typing->needOriginalData);

        /** @var list<string> $extraLocales */
        // Only the locales the engine is asked to write. A project whose
        // receiver translates in-house — Cleaning Point takes one article and
        // fills the rest itself — would otherwise get four separate articles
        // where its webhook expects one, each then translated into four
        // languages: sixteen pages for one topic.
        //
        // Filtered against the *unit's* locale further down rather than against
        // the project default, because the unit's language now follows its
        // keyword and the two are not always the same.
        $extraLocales = $context->project->writtenLocales();

        $derivativeChannels = $this->socialChannels();

        $planned = 0;

        /** @var list<array{id: string, locale: string, source_id: string}> $localeRows */
        $localeRows = [];

        $plan = DB::transaction(function () use (
            $month, $selection, $typing, $dates, $needData,
            $extraLocales, $derivativeChannels, &$planned, &$localeRows
        ): ContentPlan {
            // firstOrCreate, not create: (project, month) is unique, and
            // re-planning a month should fill the existing draft rather than
            // fail on a constraint the operator cannot see.
            $plan = ContentPlan::query()->firstOrCreate(
                ['month' => $month->toDateString()],
                ['status' => ContentPlanStatus::Draft],
            );

            foreach ($selection->selected as $index => $id) {
                $unit = ContentItem::query()->find($id);

                if ($unit === null) {
                    continue;
                }

                $unit->forceFill([
                    'content_plan_id' => $plan->getKey(),
                    'scheduled_for' => $dates[$index]->toDateString(),
                    'type' => ContentItemType::from($typing->types[$id] ?? $unit->type->value),
                    'needs_original_data' => isset($needData[$id]),
                    'planned_derivatives' => $derivativeChannels,
                ])->save();

                $planned++;

                foreach ($extraLocales as $locale) {
                    if ($locale === $unit->locale) {
                        continue;
                    }

                    if ($unit->localeVariants()->where('locale', $locale)->exists()) {
                        continue;
                    }

                    $variant = $unit->addLocale(
                        $locale,
                        $this->localisedSlug($unit->slug, $locale),
                        $unit->title,
                    );

                    $variant->forceFill([
                        'content_plan_id' => $plan->getKey(),
                        'scheduled_for' => $dates[$index]->toDateString(),
                        'needs_original_data' => isset($needData[$id]),
                        'planned_derivatives' => $derivativeChannels,
                        'intent' => $unit->intent,
                        'cluster' => $unit->cluster,
                        // `topic_difficulty` and `topic_volume` are absent on
                        // purpose, and {@see ContentItem::addLocale()} is where
                        // the reason is written out: they are per-keyword *and*
                        // per-market, so the parent's numbers are not this
                        // unit's numbers — they are another market's, wearing
                        // this one's label. This step used to copy them anyway,
                        // three lines above a comment explaining that the
                        // *figure* is a fact about a market and only the
                        // *shape* travels. A Russian and a Portuguese article
                        // shared one volume and one difficulty, and everything
                        // that reads those columns — the planner, the score,
                        // the calendar card an operator decides from — was
                        // reading a number nobody had measured.
                        //
                        // Left null until something researches that market.
                        // See `product/native-keywords-per-locale.md`.
                        //
                        // The curve travels better across locales than the
                        // figure it is the shape of. How many people search a
                        // subject is a fact about a market; *when* in the year
                        // they search it is mostly a fact about the subject —
                        // window cleaning peaks in spring whichever language
                        // the question is asked in. Without this the seasonal
                        // band of §5 would only ever fire for one locale of a
                        // multilingual unit.
                        'monthly_volumes' => $unit->monthly_volumes,
                    ])->save();

                    // Carried to {@see LocaliseVariants}, which gives the row
                    // a title and a search phrase in its own language. It is
                    // created here holding the *source* language's — copying
                    // the parent's title is how a Russian article came to be
                    // outlined from "limpeza pós-obra" and written half in
                    // Portuguese.
                    $localeRows[] = [
                        'id' => (string) $variant->getKey(),
                        'locale' => $locale,
                        'source_id' => (string) $unit->getKey(),
                    ];
                }
            }

            return $plan;
        });

        $context->remember('planning.plan_id', $plan->getKey());

        return StepResult::success(new PlanPayload(
            planId: $plan->getKey(),
            month: $month->toDateString(),
            units: $planned,
            locales: count($localeRows),
            variants: $localeRows,
        ));
    }

    /**
     * The channels this project's articles may be cut up for.
     *
     * From its own connected channels, not from a global config default. The
     * default said "linkedin, x" for every project on the installation, so a
     * project that connected Telegram got posts planned for two channels it
     * does not have and none for the one it does.
     *
     * Narrowed by `takesArticleDerivatives()` and not by `isSocial()`: Threads
     * is social and takes no cross-posts (§1, fact 3), so planning a derivative
     * for it here would put a slice of an article on the one channel where that
     * costs reach on everything published after it. Its share of the article
     * arrives through the Derivative band in `social_plan` instead.
     *
     * Falls back to the config only when nothing suitable is connected, so a
     * project set up before channels existed still plans something.
     *
     * @return list<string>
     */
    private function socialChannels(): array
    {
        $connected = array_values(array_unique(
            Channel::query()
                ->where('is_enabled', true)
                ->get()
                ->filter(static fn (Channel $channel): bool => $channel->type->takesArticleDerivatives())
                ->map(static fn (Channel $channel): string => $channel->type->value)
                ->all()
        ));

        return $connected !== []
            ? $connected
            : array_values(array_map('strval', (array) config('research.default_derivative_channels', [])));
    }

    /**
     * The slug is unique per project *and locale*, so the same slug in another
     * language is legal — but only where the languages genuinely differ. The
     * suffix keeps two locales that slug identically from colliding.
     */
    private function localisedSlug(string $slug, string $locale): string
    {
        $taken = fn (string $candidate): bool => ContentItem::query()
            ->where('slug', $candidate)
            ->where('locale', $locale)
            ->exists();

        if (! $taken($slug)) {
            return $slug;
        }

        // A loop rather than one attempt: the language-suffixed slug can be
        // taken too, and the unique index does not care that we tried once.
        $base = $slug.'-'.Str::lower(Str::before($locale, '-'));
        $candidate = $base;
        $suffix = 2;

        while ($taken($candidate)) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
