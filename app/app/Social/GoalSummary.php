<?php

declare(strict_types=1);

namespace App\Social;

use App\Enums\SocialKpi;
use App\Models\ContentGoal;
use Illuminate\Support\Carbon;

/**
 * One month's goal, as both screens that show it need to read it.
 *
 * The Plan screen asks "is this worth approving" and the Overview asks "how is
 * it going", and the two questions want the same row read two ways — the target
 * against what the account is starting from, and the target against what has
 * happened since. Written twice they drifted immediately: the confident-zero
 * rule that {@see SocialKpi} argues for lived in one controller and not the
 * other, so the same unmeasurable KPI rendered as a padlock on one screen and as
 * `0 / 500` on the next.
 *
 * Stated once, here. Both screens take the superset and render the half they
 * care about, which is cheaper than a second query and much cheaper than a
 * second opinion about what a null means.
 */
final class GoalSummary
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forMonth(Carbon $month): ?array
    {
        $goal = ContentGoal::forMonth($month);

        if ($goal === null) {
            return null;
        }

        $reporting = InsightSources::forCurrentProject();
        $measurable = $goal->kpi->isMeasurableBy($reporting);
        $published = PublishedCadence::beforeMonth($month);

        return [
            'kpi' => $goal->kpi->value,
            'kpi_label' => $goal->kpi->label(),
            'unit' => $goal->kpi->unit(),
            'target' => $goal->target,
            'cadence' => $goal->cadence,
            'weeks' => $goal->weeks,
            'confirmed' => $goal->isConfirmed(),
            'week_of' => $goal->weekOf(Carbon::now()),
            'total_weeks' => ContentGoal::WEEKS,

            // Null rather than 0, all the way to the component. "Nothing can
            // measure this yet" and "it has moved by nothing" are opposite facts
            // that render identically once the null is coerced away, and only
            // one of them is this deployment's.
            'progress' => $measurable ? 0 : null,
            'needs' => $measurable ? null : $goal->kpi->requires(),

            // What the account was doing before this month, so the proposed
            // cadence has a scale beside it. Sourced from our own published rows
            // and therefore true on every deployment, unlike the KPI above.
            'current_cadence' => PublishedCadence::weeklyRate($published),

            // The target restated as a weekly rate. The figure that decides
            // whether an operator believes the plan: a target is an abstraction
            // and "about four a week" is something a person can recognise as
            // reasonable or absurd in the second before they approve it.
            'per_week_needed' => round($goal->target / ContentGoal::WEEKS, 1),
        ];
    }

    /**
     * Every KPI with the connection it would need, measurable or not.
     *
     * All three offered on purpose. A list that hid the locked ones would leave
     * an operator unable to see that the product has the goal they want — only
     * that it does not offer it.
     *
     * @return list<array<string, mixed>>
     */
    public static function kpis(): array
    {
        $reporting = InsightSources::forCurrentProject();

        return array_map(
            static fn (SocialKpi $kpi): array => [
                'value' => $kpi->value,
                'label' => $kpi->label(),
                'unit' => $kpi->unit(),
                'measurable' => $kpi->isMeasurableBy($reporting),
                'requires' => $kpi->requires(),
            ],
            SocialKpi::cases(),
        );
    }
}
