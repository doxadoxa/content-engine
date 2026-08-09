<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Enums\ContentItemState;
use App\Enums\SignalKind;
use App\Enums\SocialBand;
use App\Models\ContentItem;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Research\StoreIdeas;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Social\GovernorVerdict;
use App\Support\Seasonality\SeasonalCurve;
use App\Support\Social\SignalWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Five sources, five budgets, one ranked list (§5).
 *
 * | Полоса      | Бюджет     | Источник                              |
 * |-------------|------------|---------------------------------------|
 * | Разговор    | без счёта  | вебхуки, `keyword_search`             |
 * | Вопросы     | 1–2/нед    | пул ключей и PAA                      |
 * | Своя фактура| ~1/нед     | данные бизнеса                        |
 * | Сезон       | ~1/нед     | помесячные кривые спроса              |
 * | Реакция     | ≤1/нед,TTL | новости, `RECENT`                     |
 * | Производные | ≤1/нед     | репурпоз                              |
 *
 * **Conversation is not gathered.** Not "gathered and then refused" — there is
 * no branch below that can produce one. It is the reply contour of §4.2, it is
 * uncounted by §5, and it is answered by a person within minutes rather than
 * placed in a slot next Thursday. {@see GovernorVerdict::refusalFor()}
 * refuses it a second time for anything that tries.
 *
 * **How the Question band avoids fighting {@see FeedPlanner}.** Both read the
 * same pool of live question signals, and the naive version of this step would
 * have taken the same rows: whichever contour ran first would create a
 * `ContentItem` pointing at the signal, and the other's
 * `whereDoesntHave('contentItems')` would then skip it forever. Listening runs
 * hourly and this runs weekly, so the article planner would win every race and
 * the social Question band would be permanently empty — a bug that looks
 * exactly like "there were no good questions this week".
 *
 * So the two are staged rather than competing, and the staging is §6's first
 * consequence read literally: "Тема, по которой в Threads есть разговор и
 * отклик, а на сайте нет страницы, уходит в планировщик статей с приоритетом."
 * The gap is the article's to fill. What this band takes is a question the
 * listener has *already* routed to an article that is not published yet — the
 * conversation exists, the page does not, and the post is what stands in the
 * gap while the page is written. It carries the same `signal_id`, so §3's
 * attribution shows one signal producing both, and §1.3's claim — Threads finds
 * what to write about and then distributes it — becomes two rows instead of an
 * argument.
 *
 * A question the listener has not routed is left alone on purpose. Taking it
 * would silently cancel the article, which §1.3 says is the more valuable half.
 *
 * **The Derivative band schedules rather than writes.** The repurpose pipeline
 * already saves a `SocialPost` with the band set. Creating a second unit here
 * would publish one article twice and charge §5's budget twice, so this band
 * hands {@see PlaceSlots} an existing row to give a time to. It is also the
 * only band whose text exists before the slot does, which is why it is the one
 * band `social_draft` will have nothing to do for.
 */
class GatherCandidates extends AbstractStep
{
    /**
     * How long a subject stays said.
     *
     * Bands with no signal behind them — own data, season — are deduplicated on
     * the subject rather than on a row, because the source is a config value
     * and a demand curve, neither of which is consumed by being used. Without
     * this the same price fact is a candidate every week forever.
     */
    private const int SUBJECT_COOLDOWN_DAYS = 90;

    /**
     * How pronounced a peak has to be before six weeks of the calendar are
     * committed to it.
     *
     * {@see SeasonalCurve::strength()} deliberately refuses to pick this number
     * itself. 1.3 means the peak month is a third above the average month,
     * which is a season; below it the curve is vendor rounding, and §5's
     * seasonal band is worth having only where the demand is real.
     */
    private const float MIN_SEASON_STRENGTH = 1.3;

    /**
     * The bands with no signal to inherit a weight from, on the 0–100 scale of
     * {@see SignalWeight}.
     *
     * They are not arbitrary; they are where each band sits against the
     * governor's selection bar, which is 50 normally and 64 in a throttled week
     * (§4.3, "поднимает планку отбора"). Own data clears both, because §5 calls
     * it the thing nobody else has and a weak reply rate is not a reason to
     * stop saying the only thing that is ours. A derivative clears the ordinary
     * bar and not the raised one, so the repurpose band is the first to drop
     * out of a bad week — which is §1's third fact applied rather than merely
     * quoted: a post cut from an article reads like a post cut from an article,
     * and that is the last thing an account with a narrowing display window
     * should be spending a slot on.
     */
    private const int OWN_DATA_WEIGHT = 75;

    private const int DERIVATIVE_WEIGHT = 55;

    /** How deep each band reads. Bounded: a year-old project has a pool. */
    private const int POOL_DEPTH = 50;

    public static function key(): string
    {
        return 'gather_candidates';
    }

    public function handle(StepContext $context): StepResult
    {
        $said = $this->recentSubjects();

        $candidates = [];
        $empty = [];

        foreach (
            [
                SocialBand::Reaction->value => $this->reactions(...),
                SocialBand::Question->value => $this->questions(...),
                SocialBand::OwnData->value => fn (): array => $this->ownData($context, $said),
                SocialBand::Season->value => fn (): array => $this->seasonal($said),
                SocialBand::Derivative->value => $this->derivatives(...),
            ] as $band => $source
        ) {
            $found = $source();

            if ($found === []) {
                // Not a failure and not silence. §7 wants the empty slot to
                // carry a reason, and "this band had nothing this week" is the
                // commonest one there is.
                $empty[$band] = 'nothing in this band was worth a slot this week';

                continue;
            }

            $candidates = [...$candidates, ...$found];
        }

        // Ranked once, here, so {@see PlaceSlots} is a walk rather than a
        // second opinion. Band order first and weight second: a reaction dies
        // (§5's TTL) and a question is the format §5 says works best, so those
        // two get the earliest windows of the week whatever else is waiting.
        usort($candidates, static function (SlotCandidate $a, SlotCandidate $b): int {
            return [self::priority($a->band), -$a->weight] <=> [self::priority($b->band), -$b->weight];
        });

        $context->remember('social_plan.candidates', count($candidates));

        return StepResult::success(new SlotCandidatesPayload($candidates, $empty));
    }

    /**
     * The reactive band: news inside its window (§5).
     *
     * The TTL travels to the unit, because §5 kills a draft that misses its
     * window rather than publishing it late, and the thing being killed is the
     * draft rather than the signal.
     *
     * @return list<SlotCandidate>
     */
    private function reactions(): array
    {
        $news = Signal::query()
            ->live()
            ->where('kind', SignalKind::News->value)
            ->whereNotNull('expires_at')
            ->whereDoesntHave('contentItems')
            ->orderByDesc('weight')
            ->orderByDesc('occurred_at')
            ->limit(self::POOL_DEPTH)
            ->get();

        return array_values($news
            ->map(fn (Signal $signal): SlotCandidate => new SlotCandidate(
                band: SocialBand::Reaction,
                title: $signal->title,
                entities: $signal->entities,
                weight: $signal->weight,
                why: 'a news item still inside its window',
                signalId: (string) $signal->getKey(),
                targetQuery: $signal->title,
                expiresAt: $signal->expires_at?->toImmutable(),
            ))
            ->all());
    }

    /**
     * The question band: a live conversation whose page does not exist yet.
     *
     * See the class docblock for why this waits for {@see FeedPlanner} rather
     * than racing it. The two conditions are the whole of the rule: the
     * listener has already commissioned the article (`whereHas`, roots, not
     * published), and nobody has already planned a post for it
     * (`whereDoesntHave`, social).
     *
     * @return list<SlotCandidate>
     */
    private function questions(): array
    {
        $questions = Signal::query()
            ->live()
            ->where('kind', SignalKind::Question->value)
            ->whereHas('contentItems', self::articleStillUnwritten(...))
            ->whereDoesntHave('contentItems', self::alreadyAPost(...))
            ->orderByDesc('weight')
            ->orderByDesc('occurred_at')
            ->limit(self::POOL_DEPTH)
            ->get();

        return array_values($questions
            ->map(fn (Signal $signal): SlotCandidate => new SlotCandidate(
                band: SocialBand::Question,
                title: $signal->title,
                entities: $signal->entities,
                weight: $signal->weight,
                why: 'somebody asked this and the site has no page for it yet',
                signalId: (string) $signal->getKey(),
                targetQuery: $signal->title,
            ))
            ->all());
    }

    /**
     * An article the listener commissioned that is not on the site yet.
     *
     * A named method rather than a closure only so that the relation's builder
     * can be typed: `roots()` and `social()` are local scopes, and an untyped
     * closure parameter hides which model they belong to.
     *
     * @param  Builder<ContentItem>  $query
     */
    private static function articleStillUnwritten(Builder $query): void
    {
        $query->roots()->whereNot('state', ContentItemState::Published->value);
    }

    /** @param  Builder<ContentItem>  $query */
    private static function alreadyAPost(Builder $query): void
    {
        $query->social();
    }

    /**
     * The own-data band: what the business knows and nobody else does (§5).
     *
     * Straight off `projects.original_data`, which the onboarding wizard fills
     * and which generation already reads for exactly this reason. No signal,
     * because a price is not an event — which is also why the cooldown is on
     * the subject rather than on a row.
     *
     * @param  array<string, int>  $said  normalised subject => anything
     * @return list<SlotCandidate>
     */
    private function ownData(StepContext $context, array $said): array
    {
        $candidates = [];

        foreach (array_keys($context->project->original_data) as $fact) {
            $fact = (string) $fact;
            $normalised = StoreIdeas::normalise($fact);

            if ($normalised === '' || isset($said[$normalised])) {
                continue;
            }

            $candidates[] = new SlotCandidate(
                band: SocialBand::OwnData,
                title: Str::headline($fact),
                entities: [],
                weight: self::OWN_DATA_WEIGHT,
                why: 'a fact about this business that nobody else has',
                targetQuery: $fact,
            );
        }

        return $candidates;
    }

    /**
     * The seasonal band: four to six weeks before a peak, and not at it (§5).
     *
     * {@see SeasonalCurve::isPlanningWindow()} is the whole test and it is
     * asked of the unit's own curve, which research stored and which
     * `KeywordIdea` used to throw away. A subject inside its peak is
     * deliberately not a candidate: the window has already opened, the work
     * needed to be live before demand rose, and posting into it is the
     * seasonal band pretending to be the reactive one.
     *
     * @param  array<string, int>  $said
     * @return list<SlotCandidate>
     */
    private function seasonal(array $said): array
    {
        $subjects = ContentItem::query()
            ->roots()
            ->whereNotNull('monthly_volumes')
            ->limit(self::POOL_DEPTH)
            ->get();

        $candidates = [];

        foreach ($subjects as $subject) {
            $curve = $subject->seasonality();

            if (! $curve->isPlanningWindow()) {
                continue;
            }

            $strength = $curve->strength();

            if ($strength === null || $strength < self::MIN_SEASON_STRENGTH) {
                continue;
            }

            $normalised = StoreIdeas::normalise($subject->target_query ?? $subject->title);

            if ($normalised === '' || isset($said[$normalised])) {
                continue;
            }

            $candidates[] = new SlotCandidate(
                band: SocialBand::Season,
                title: $subject->title,
                entities: $subject->entities,
                // A peak a third above the average month — the weakest this
                // band admits at all, see MIN_SEASON_STRENGTH — scores 65, and
                // a peak twice the average scores at the top of the scale, so a
                // stronger season outranks a weaker one. Both ends clear the
                // throttled bar of 64: seasonality is planned four to six weeks
                // out and a weak reply rate today is not a reason to miss a
                // peak that arrives after it has recovered. The band the
                // throttle drops is the repurpose one below, which is §1's
                // third fact rather than a matter of degree.
                weight: (int) min(
                    SignalWeight::MAX,
                    50 + (int) round(($strength - 1.0) * 50),
                ),
                why: 'four to six weeks before this subject peaks',
                // No `parentId`, though the curve came off an article. §3 makes
                // parentage optional for a social unit and `isDerivative()`
                // means something — a seasonal post is not a cut of the page it
                // shares a subject with, and claiming otherwise would put it in
                // the repurpose band's tree and out of the Season budget.
                targetQuery: $subject->target_query ?? $subject->title,
            );
        }

        return $candidates;
    }

    /**
     * The derivative band: something repurpose already cut, with no time on it.
     *
     * `slot_at` is the test rather than the state, because a derivative sits in
     * `idea` or `draft` depending on how far repurpose got, and neither answers
     * the question being asked here — which is "has anybody decided when this
     * goes out".
     *
     * @return list<SlotCandidate>
     */
    private function derivatives(): array
    {
        $units = ContentItem::query()
            ->social()
            ->where('social_band', SocialBand::Derivative->value)
            ->whereNull('slot_at')
            ->whereNull('published_at')
            ->whereIn('state', [ContentItemState::Idea->value, ContentItemState::Draft->value])
            ->orderBy('created_at')
            ->limit(self::POOL_DEPTH)
            ->get();

        return array_values($units
            ->map(fn (ContentItem $unit): SlotCandidate => new SlotCandidate(
                band: SocialBand::Derivative,
                title: $unit->title,
                entities: $unit->entities,
                weight: self::DERIVATIVE_WEIGHT,
                why: 'a repurpose cut that has never been given a time',
                parentId: $unit->parent_id,
                unitId: (string) $unit->getKey(),
                targetQuery: $unit->target_query,
            ))
            ->all());
    }

    /**
     * Subjects this project has already posted about lately.
     *
     * Normalised through {@see StoreIdeas::normalise()} and not through a
     * second definition written here. Two readings of "the same topic" drift,
     * and the day they do the planner starts posting a subject it posted last
     * week — which on this channel is not a duplicate, it is §1's penalty
     * arriving on the next post.
     *
     * @return array<string, int>
     */
    private function recentSubjects(): array
    {
        return ContentItem::query()
            ->social()
            ->whereNotNull('target_query')
            ->where('created_at', '>=', now()->subDays(self::SUBJECT_COOLDOWN_DAYS))
            ->pluck('target_query')
            ->map(static fn (mixed $query): string => StoreIdeas::normalise((string) $query))
            ->flip()
            ->all();
    }

    /** Which band gets the earliest window of the week. */
    private static function priority(SocialBand $band): int
    {
        return match ($band) {
            // Dies first, so it goes first (§5's TTL).
            SocialBand::Reaction => 0,
            // "Вопрос лучший формат" (§5).
            SocialBand::Question => 1,
            SocialBand::OwnData => 2,
            SocialBand::Season => 3,
            SocialBand::Derivative => 4,
            // Unreachable: nothing above produces one, and the governor refuses
            // it as well. Listed so the match stays exhaustive and a sixth band
            // added later cannot be silently ranked first.
            SocialBand::Conversation => 99,
        };
    }
}
