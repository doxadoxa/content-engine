<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Content\SubjectLocaliser;
use App\Media\HeroImage;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;

/**
 * Say what each locale row is about, in its own language (§2).
 *
 * {@see ScheduleCalendar} creates a locale row by copying the unit it came
 * from, and a copy carries the source language's title and target query. That
 * was invisible for as long as the copy was only a placeholder, and it stopped
 * being invisible the moment generation read it: `write_outline` for a Russian
 * article was prompted with
 *
 *     Target query: limpeza pós-obra
 *     Working title: limpeza pós-obra
 *     Language: ru
 *
 * and answered accordingly — an outline whose entities came back as "azulejo
 * português", "caixilharia de alumínio" and "espuma de poliuretano", which
 * `cover_entities` then required the draft to name. The published Russian
 * article read "Espuma de poliuretano, или монтажная пена" and "Limpeza
 * pós-obra подходит не для каждого случая". The calendar had been saying it all
 * along: three locale cards, one Portuguese title on all of them.
 *
 * A translation of the *subject*, not a search for a new one. The three rows of
 * a locale group are one unit in three languages — that is what makes them
 * shareable, from the repurpose tree down to the photographs
 * {@see HeroImage} lends between them — so the subject stays fixed
 * and only its expression changes.
 *
 * What this deliberately does **not** do is find the phrase Russians actually
 * search for. `target_query` here is the subject said in the right language; it
 * is not a researched keyword, has no volume behind it, and must not be read as
 * one. That is its own piece of work — see
 * `product/native-keywords-per-locale.md` — and the honest interim is a row
 * whose language is right and whose market numbers are absent rather than
 * borrowed.
 *
 * One model call per unit rather than per row: the locales of a unit are one
 * question ("say this in these languages"), the output is bounded, and a unit
 * whose answer comes back unusable loses its own titles rather than the month's.
 */
class LocaliseVariants extends AbstractStep
{
    public function __construct(private readonly SubjectLocaliser $localiser) {}

    public static function key(): string
    {
        return 'localise_variants';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [ScheduleCalendar::key()];
    }

    public function queue(): string
    {
        // It calls a model, and the cheap pool's workers are given two minutes.
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        $plan = $context->output(ScheduleCalendar::key(), PlanPayload::class);

        if ($plan->variants === []) {
            // A single-locale project, or a month whose rows all existed
            // already. Nothing to say in another language.
            return StepResult::skip('This month added no locale rows.');
        }

        $localised = 0;
        $failed = 0;

        foreach ($this->bySource($plan->variants) as $sourceId => $wanted) {
            $source = ContentItem::query()->find($sourceId);

            if ($source === null) {
                continue;
            }

            $said = $this->localiser->for($context, $source, array_values(array_unique($wanted)));

            foreach ($wanted as $variantId => $locale) {
                $line = $said[$locale] ?? null;

                if ($line === null) {
                    // Left as the source's title, which is what it already
                    // says. A plan with an untranslated card is worth more than
                    // no plan, and the operator can see it on the calendar.
                    $failed++;

                    continue;
                }

                $variant = ContentItem::query()->find($variantId);

                if ($variant === null) {
                    continue;
                }

                $variant->forceFill([
                    'title' => $line['title'],
                    'target_query' => $line['query'],
                    // The slug was built from the source's, so it reads as
                    // Portuguese on a Russian page. Rebuilt from the title it
                    // now has, and only while nothing has been written yet —
                    // a slug is an address, and this step runs before the unit
                    // has one anybody could have followed.
                    'slug' => $this->localiser->slugFor($variant, $line['title']),
                ])->save();

                $localised++;
            }
        }

        if ($failed > 0) {
            $context->remember('planning.untranslated_locale_rows', $failed);
        }

        return StepResult::success(new LocalisationPayload($localised, $failed));
    }

    /**
     * @param  list<array{id: string, locale: string, source_id: string}>  $variants
     * @return array<string, array<string, string>> source id => (variant id => locale)
     */
    private function bySource(array $variants): array
    {
        $bySource = [];

        foreach ($variants as $variant) {
            $bySource[$variant['source_id']][$variant['id']] = $variant['locale'];
        }

        return $bySource;
    }
}
