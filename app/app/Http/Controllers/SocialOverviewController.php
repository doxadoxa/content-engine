<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SocialKpi;
use App\Models\ContentGoal;
use App\Models\ContentPlan;
use App\Social\ActionBoard;
use App\Social\GoalSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The social surface's Overview: what the month is for, and what is left of it.
 *
 * The screen the Studio never was. The Studio answers "what should this month
 * say" and answers it well; it has never been able to answer "what do I do
 * now", because every action on it is scoped to a whole month and there is no
 * number on it that a person could be held to. This one is scoped to a day's
 * work and carries the goal above it.
 *
 * **Not gated on `social.enabled`.** That flag is about whether this
 * installation has a Threads presence — a publisher, a webhook, an OAuth
 * grant — and this board reads `content_ideas` and `content_items`, which the
 * Studio writes on every installation whether or not anything can publish them.
 * Hiding the board where the flag is off would hide a month of real work
 * because of a connection it does not need.
 */
class SocialOverviewController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $this->month((string) $request->query('month', now()->format('Y-m')));
        $plan = ContentPlan::query()->whereDate('month', $month)->first();
        $goal = ContentGoal::forMonth($month);

        $cards = $plan === null ? collect() : ActionBoard::cards($plan);

        // The cadence's promise where a goal exists, and the month's own ideas
        // where one does not. A board with no goal still has a denominator
        // worth showing — it is simply the plan's rather than the operator's.
        $planned = $goal?->plannedPosts() ?? $cards->sum('planned');

        return Inertia::render('social/index', [
            'month' => $month->format('Y-m'),
            'label' => $month->translatedFormat('F Y'),
            'previous' => $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->format('Y-m'),
            'has_plan' => $plan !== null && $plan->assistant_version > 0,
            'goal' => GoalSummary::forMonth($month),
            'progress' => ActionBoard::progress($plan, (int) $planned),
            'columns' => [
                ActionBoard::TODO => $cards->where('column', ActionBoard::TODO)->values()->all(),
                ActionBoard::IN_PROGRESS => $cards->where('column', ActionBoard::IN_PROGRESS)->values()->all(),
                ActionBoard::DONE => $cards->where('column', ActionBoard::DONE)->values()->all(),
            ],
        ]);
    }

    /**
     * Overrule the numbers the assistant proposed.
     *
     * No longer the way a goal comes into being — the proposal writes one, sized
     * against the account's own history, and this exists for the operator who
     * disagrees with it. That is a much narrower job than the blank form it
     * replaces, and a better one: disagreeing with 340 is a judgement anybody
     * can make, whereas inventing the first number from nothing is a question
     * whose honest answer is "I don't know, what is realistic?" — which the
     * blank field then answered with its own placeholder.
     *
     * Still confirmed by the same request that writes it. Typing a number *is*
     * the decision; a second click that only says "yes, the thing I just typed"
     * is a step with nothing in it. Approving the proposal confirms the
     * assistant's numbers the same way — see `ContentStudioAssistant::accept()`.
     *
     * Posted from the Plan screen, beside the proposal it is arguing with,
     * rather than from the Overview. It stays on this controller because the
     * goal is the Overview's subject and the route has always been its.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'kpi' => ['required', 'string', 'in:'.implode(',', array_column(SocialKpi::cases(), 'value'))],
            // A target of nothing is not a goal. The ceiling is not a product
            // rule so much as a guard against a typed extra zero becoming a
            // month of progress reported as 0%.
            'target' => ['required', 'integer', 'min:1', 'max:100000000'],
            'cadence' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $month = $this->month($validated['month']);

        ContentGoal::query()->updateOrCreate(
            ['month' => $month],
            [
                'kpi' => SocialKpi::from($validated['kpi']),
                'target' => (int) $validated['target'],
                'cadence' => (int) $validated['cadence'],
                'confirmed_at' => now(),
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'This month has a goal.',
        ]);

        return back();
    }

    /**
     * `?month=` as the first of that month.
     *
     * **The bang is load-bearing.** Without it `createFromFormat` fills every
     * unspecified field from *now*, so the day defaults to today's day of the
     * month — and on the 29th to the 31st, "2026-02" parses to the 31st of
     * February, which rolls into March. `startOfMonth()` then dutifully returns
     * March. For three days a month this screen showed a different month than
     * the one it was asked for, and `store()` wrote a confirmed goal against it.
     *
     * The shape is checked before parsing so a malformed query is a 422 rather
     * than an uncaught exception, which is what the Plan tab has always done —
     * these two tabs simply drifted from it.
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
