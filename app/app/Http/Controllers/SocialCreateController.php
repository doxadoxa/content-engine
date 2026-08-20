<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PostKind;
use App\Models\ContentPlan;
use App\Models\Signal;
use App\Social\ActionBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Where a post comes from.
 *
 * Three shelves in the order of how much they already know about what to say:
 * the month's own unwritten ideas, the reasons the world has handed us, and a
 * blank sheet.
 *
 * **The signals shelf is the only one that brings anything in from outside.**
 * Every idea this engine has ever had came from a model reading our own website
 * once a month, which is a closed loop that can only restate what the site
 * already says — the "sounds like stock" complaint one level up from pictures.
 * `signals` was already a table with sources, scoring, deduplication and an
 * expiry; what it never had was a surface, so nothing a person could see ever
 * came of it.
 */
class SocialCreateController extends Controller
{
    /** How many ideas the shelf offers at once. */
    private const int SHELF = 6;

    public function __invoke(Request $request): Response
    {
        $month = $this->month((string) $request->query('month', now()->format('Y-m')));

        $plan = ContentPlan::query()->whereDate('month', $month)->first();

        // Six, not fourteen. A shelf of every unwritten idea in the month is a
        // decision nobody makes: the reference product shows six and it is the
        // reason its Create tab reads as a choice rather than a backlog. The
        // rest are not hidden — they are on the board, in date order, which is
        // where "what is left this month" belongs.
        $all = $plan === null
            ? collect()
            : ActionBoard::cards($plan)->where('column', ActionBoard::TODO)->values();

        $ideas = $all->take(self::SHELF)->values();

        return Inertia::render('social/create', [
            'month' => $month->format('Y-m'),
            'label' => $month->translatedFormat('F Y'),
            'ideas' => $ideas->all(),
            // What the shelf is a slice of, so the screen can say "6 of 14"
            // rather than implying the month holds six ideas.
            'idea_total' => $all->count(),
            'signals' => $this->signals(),
            'kinds' => array_map(
                static fn (PostKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'channels' => array_map(
                        static fn ($channel): string => $channel->value,
                        $kind->channels(),
                    ),
                ],
                PostKind::cases(),
            ),
        ]);
    }

    /**
     * Reasons that are still reasons.
     *
     * `live()` is the model's own definition — unconsumed, and not past its
     * expiry — so a shelf cannot offer somebody a trend from March. Ordered by
     * weight then recency because the shelf is a shortlist and the question it
     * answers is "what is worth saying something about", not "what arrived
     * last".
     *
     * @return list<array<string, mixed>>
     */
    private function signals(): array
    {
        /** @var list<array<string, mixed>> $signals */
        $signals = Signal::query()
            ->live()
            ->orderByDesc('weight')
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get(['id', 'kind', 'source', 'title', 'url', 'entities', 'occurred_at', 'weight'])
            ->map(static fn (Signal $signal): array => [
                'id' => (string) $signal->getKey(),
                'kind' => $signal->kind->value,
                'kind_label' => $signal->kind->label(),
                'source' => $signal->source->value,
                'source_label' => $signal->source->label(),
                'title' => $signal->title,
                'url' => $signal->url,
                'entities' => $signal->entities,
                'occurred_at' => $signal->occurred_at->toDateString(),
                'weight' => $signal->weight,
            ])
            ->values()
            ->all();

        return $signals;
    }

    /**
     * `?month=` as the first of that month.
     *
     * **The bang is load-bearing.** Without it `createFromFormat` fills every
     * unspecified field from *now*, so the day defaults to today's day of the
     * month — and on the 29th to the 31st, "2026-02" parses to the 31st of
     * February and rolls into March. This shelf then offered March's ideas
     * under February's heading for three days a month.
     *
     * Shape-checked before parsing so a malformed query is a 422 rather than an
     * uncaught exception, matching the Plan tab.
     */
    private function month(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            abort(422, 'Month must be YYYY-MM.');
        }

        try {
            $month = Carbon::createFromFormat('!Y-m', $value);
        } catch (Throwable) {
            abort(422, 'Month must be YYYY-MM.');
        }

        if ($month === null || $month->format('Y-m') !== $value) {
            abort(422, 'Month must be YYYY-MM.');
        }

        return $month->startOfMonth()->startOfDay();
    }
}
