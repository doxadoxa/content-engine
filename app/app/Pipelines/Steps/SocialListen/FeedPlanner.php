<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\SearchIntent;
use App\Enums\SignalKind;
use App\Models\ContentItem;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Pipelines\Steps\Research\StoreIdeas;
use App\Support\Social\SignalWeight;
use Illuminate\Support\Str;

/**
 * The reverse flow (§1.3) — the point of the whole contour.
 *
 * > Обратный поток важнее прямого: вопросы из `keyword_search` и из ответов под
 * > своими постами уходят в планировщик статей. Статья ранжируется и цитируется,
 * > Threads находит, о чём её писать, и распространяет её.
 *
 * A question somebody actually asked becomes a `ContentItem` in `idea`, in the
 * same pool the research pipeline fills and the planner of §4.2 reads. Nothing
 * about the unit says where it came from except `signal_id`, and that column is
 * the entire reason `signals` is a table (§3): a month from now, "does the
 * listening contour pay for itself" is a group-by rather than an argument.
 *
 * **Why this is worth more than the forward flow.** §1 forbids the engine from
 * inventing volume, and §1.3 puts the counter-measure to scaled content abuse
 * in "оригинальная фактура" — original material. A keyword tool cannot produce
 * it: it returns strings people typed into a search box, stripped of why. A
 * question in a thread arrives with its own words, its own context and its own
 * confusion, and that is the raw material the article planner has never had.
 *
 * **Corpus dedup, exactly as {@see StoreIdeas} does it.** One query against the
 * `target_query` of every article the project has — in any state, idea or
 * planned or published — normalised the same way by
 * {@see StoreIdeas::normalise()}. Sharing the normalisation rather than writing
 * a second one is the load-bearing part: two definitions of "the same topic"
 * drift, and the day they do, the listening contour starts planning articles
 * the site already has.
 *
 * `roots()` is what makes that query about articles. A social post carries a
 * `target_query` too, and a post asking about a subject is the §1.3 reason to
 * write the article about it — counting the post as coverage would invert the
 * most important claim in the spec, one topic at a time. {@see StoreIdeas} says
 * the same thing at the same query, and {@see SelectTopics}
 * a third time. All three are the same sentence.
 *
 * **The input is the live pool, not this hour's arrivals.** The step used to
 * plan from the ids {@see StoreSignals} had just written, which quietly made
 * every decision it takes final. A question held back for {@see MAX_PER_RUN},
 * or under {@see MIN_WEIGHT}, or because the site already covered it, was never
 * looked at again by anything — and it could not come back either, because the
 * row stays live and {@see Deduplicate} rejects the same subject on every
 * future run. Six questions in one hot hour produced one article and five
 * subjects nobody could raise again. So the pool is the input: every live,
 * unconsumed question this project holds that has not already produced a unit,
 * heaviest first. `StoreSignals`'s output is now a precondition and a metric —
 * it says the intake ran — rather than the list.
 *
 * That also makes the "no" a *this hour's* no. The corpus grows, weights are
 * corroborated upward, and a run that skipped four questions to stay under its
 * ceiling picks them up next hour with nothing lost.
 *
 * **The signal is not marked consumed.** `markConsumed()` means "a plan or a
 * draft used it", and it takes the row out of {@see Signal::scopeLive()} —
 * which is the social planner's only entry point. A question is a reason to
 * write an article *and* a reason to post, and consuming it here would let the
 * article silently cancel the post. What keeps the pool from re-planning a
 * question it already planned is `content_items.signal_id`: the unit points at
 * the signal, the query below excludes any signal a unit points at, and the
 * same column keeps the reaper off it (see {@see Signal::scopeReapable()}).
 * {@see Deduplicate} closes the other half — the same subject arriving again is
 * matched against the open queue the new idea is now in.
 */
class FeedPlanner extends AbstractStep
{
    /**
     * How many questions one hour may add to the pool.
     *
     * A ceiling, in the sense §5 means it. A hot day on the platform can
     * produce forty questions about one product launch, and an idea pool with
     * forty near-identical rows in it is a planner that spends a month on one
     * subject. The dedup of §4.1 catches the near-identical ones; this catches
     * the day the dedup is right and there really are forty.
     */
    private const int MAX_PER_RUN = 5;

    /**
     * Below this, a question is not worth an article.
     *
     * **What it actually gates, stated honestly: staleness.** A question starts
     * at 45 and can earn 20 for resolved entities and 20 for freshness
     * ({@see SignalWeight} has the arithmetic), so the only way under 50 is a
     * conjunction — nothing resolved *and* roughly two and a half days old. A
     * question that resolves into none of the project's entities and was asked
     * an hour ago scores 65 and becomes an idea. This docblock used to claim
     * the first half of that conjunction on its own, which read like an
     * entity gate and was not one.
     *
     * **And there is deliberately no entity gate here.** §4.3 makes entity
     * resolution a hard gate on a native *post*, and the reverse flow of §1.3
     * is not a post: it is a question going to the article planner. Three
     * reasons the gate does not carry across.
     *
     * 1. The half of §1.3 with the most value in it would be the first thing
     *    refused. "Обратный поток… вопросы из `keyword_search` **и из ответов
     *    под своими постами**" — and a reply under our own post is exactly the
     *    place where nobody repeats the subject: "does vinegar actually work on
     *    this?" resolves to nothing at all and is the best article idea in the
     *    hour.
     * 2. §6 makes the unresolved subject a *priority* rather than a rejection:
     *    "Тема, по которой в Threads есть разговор и отклик, а на сайте нет
     *    страницы, уходит в планировщик статей с приоритетом." Entities are
     *    read off the corpus, so "resolves into nothing" is frequently the
     *    literal statement that the site has no page about it.
     * 3. Relevance is already established upstream and cheaply: the post was
     *    found by one of the project's own vocabulary terms (§4.1), or it is a
     *    reply under something the project itself published. It reached this
     *    step because it is this project's business, not because it named it.
     *
     * What stops an unresolved question becoming a bad article is downstream
     * and unchanged — the idea competes in the pool, the planner ranks it, and
     * §4.3's gates apply to whatever is written.
     */
    private const int MIN_WEIGHT = 50;

    /**
     * How deep into the pool one run reads.
     *
     * The pool is ordered heaviest first and only {@see MAX_PER_RUN} of it can
     * be taken, so everything past this bound is a question that already lost
     * on weight to fifty better ones. Bounded rather than unbounded because
     * this runs hourly and a project that has been listening for a month has a
     * pool, not a handful of rows.
     */
    private const int POOL_DEPTH = 50;

    public static function key(): string
    {
        return 'feed_planner';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [StoreSignals::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(StoreSignals::key())) {
            // The intake did not run at all — no Threads connection and no
            // feeds. Reading the pool anyway would be planning from a contour
            // that is switched off for this project.
            return StepResult::skip('Nothing was stored, so there is nothing to plan from.');
        }

        // Every question still worth an article, not only the ones this hour
        // brought in. `live()` is "inside its window and not already used";
        // `whereDoesntHave()` drops the ones that already became a unit, which
        // is what the absence of `markConsumed()` here leaves to be checked —
        // and, since nothing in the engine writes `consumed_at` at all yet
        // (⚠️ see `Signal`), it is the only thing checking it.
        $questions = Signal::query()
            ->live()
            ->where('kind', SignalKind::Question->value)
            ->whereDoesntHave('contentItems')
            ->orderByDesc('weight')
            ->orderByDesc('occurred_at')
            ->limit(self::POOL_DEPTH)
            ->get();

        if ($questions->isEmpty()) {
            // A valid and frequent outcome. §4.3 makes "сегодня не публикуем" a
            // real answer, and the listening contour's equivalent is an hour in
            // which nobody asked anything worth writing about.
            return StepResult::success(new PlannedIdeasPayload([], []));
        }

        // One query rather than one per question — the same reasoning as
        // {@see StoreIdeas}, whose normalisation this shares.
        $known = ContentItem::query()
            ->roots()
            ->whereNotNull('target_query')
            ->pluck('target_query')
            ->map(static fn (mixed $query): string => StoreIdeas::normalise((string) $query))
            ->flip();

        $created = [];
        $skipped = [];

        foreach ($questions as $question) {
            if (count($created) >= self::MAX_PER_RUN) {
                $skipped[$question->title] = 'over what one listening run may add to the pool';

                continue;
            }

            if ($question->weight < self::MIN_WEIGHT) {
                $skipped[$question->title] = 'not weighty enough to be worth an article';

                continue;
            }

            $normalised = StoreIdeas::normalise($question->title);

            if ($normalised === '' || $known->has($normalised)) {
                $skipped[$question->title] = 'the site already covers this';

                continue;
            }

            $created[] = $this->plan($context, $question);

            // Added to the seen set as well as the database, so two phrasings
            // of one question inside a single hour cannot both get through.
            // The value is never read — `flip()` built this as query =>
            // position, and matching that type keeps the collection homogeneous.
            $known->put($normalised, 0);
        }

        // Both numbers, because the interesting one is the gap between them:
        // a pool that grows every hour while `planned` stays at zero is a
        // project whose questions the filters above are all refusing.
        $context->remember('social_listen.pool', $questions->count());
        $context->remember('social_listen.planned', count($created));

        return StepResult::success(new PlannedIdeasPayload($created, $skipped));
    }

    /**
     * One question, as a unit in `idea`.
     *
     * No `state`: it is not fillable on purpose, a unit starts at `idea` from
     * the model's own defaults, and the only thing allowed to move it
     * afterwards is the state machine. `StoreIdeas` says the same.
     */
    private function plan(StepContext $context, Signal $question): string
    {
        $intent = SearchIntent::Informational;

        $idea = ContentItem::query()->create([
            // The signal that caused it — §3's whole reason for the table, and
            // the phase's exit criterion.
            'signal_id' => $question->getKey(),
            // The project's own language rather than the poster's. A question
            // is evidence that somebody wants this answered; the article that
            // answers it belongs on the site, in the language the site is in.
            // The Threads post keeps the original wording in `signals.raw`.
            'locale' => $context->project->default_locale,
            'type' => $intent->suggestedType(),
            'slug' => $this->slug($question->title),
            // The question as it was asked, not a title-cased rewrite of it.
            // §4.1's "живые вопросы живыми словами" is the whole value here,
            // and `Str::headline()` would launder exactly that out of it. The
            // writer names the article at the end of generation.
            'title' => $question->title,
            'target_query' => $question->title,
            // Already resolved into the project's vocabulary at intake, which
            // is the work §4.3 says must not be repeated per draft.
            'entities' => $question->entities,
            'intent' => $intent->value,
            // Filed under its own subject. Research clusters by keyword family;
            // a question from a conversation has no family until the planner
            // gives it one, and inventing a parent topic here would put it in
            // the wrong one.
            'cluster' => $question->title,
            'planned_derivatives' => [],
        ]);

        return (string) $idea->getKey();
    }

    /**
     * Unique per project, because the database says so.
     *
     * The suffix is only reached when two different questions slug to the same
     * thing — which questions do far more often than keywords, since a slug of
     * a sentence is mostly stop words.
     */
    private function slug(string $question): string
    {
        $base = Str::limit(Str::slug($question), 80, '') ?: 'question';
        $slug = $base;
        $suffix = 2;

        while (ContentItem::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
