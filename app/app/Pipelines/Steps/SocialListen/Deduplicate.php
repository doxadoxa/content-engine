<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Enums\ContentItemState;
use App\Enums\SignalSource;
use App\Models\ContentItem;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Planning\SelectTopics;
use App\Support\Social\SignalWeight;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The central requirement of §4.1.
 *
 * > Дедуп против опубликованного за 30 дней **и против очереди**: один горячий
 * > сигнал иначе порождает пять почти одинаковых черновиков.
 *
 * Two halves, and the second is the one that produces the five drafts. A dedup
 * that only looks at what has been published is correct on the day it is
 * written and useless within the hour: nothing has been published yet, because
 * the drafts are still sitting in the queue waiting for an operator, and the
 * next hourly run has no idea they exist. So the window is both — thirty days
 * of publications, and everything currently open.
 *
 * **Keyed on {@see Signal::fingerprintFor()}, never on the platform's id.** Its
 * docblock exists for this step: one hot subject arrives from `keyword_search`,
 * from a webhook and from two feeds under four different ids, and a window
 * keyed on the id lets all four through. Keyed on the normalised subject and
 * the resolved entity set, they collapse to one — which is also why entity
 * resolution had to happen at intake rather than per draft.
 *
 * Four things are compared against, and each of them is a duplicate somebody
 * would otherwise write:
 *
 * 1. **The batch itself.** Search and RSS deliver the same story in the same
 *    hour. Collapsed to the heaviest, and the survivor is scored up: one
 *    subject from two independent intakes is the strongest corroboration this
 *    contour produces (§4.1's five near-identical drafts, arriving all at once).
 *    *Two intakes*, and the qualifier is load-bearing — see the collision
 *    branch in `handle()`, which pays the bonus only for an arrival that is
 *    demonstrably a different post from the one already kept.
 * 2. **Signals already in the table.** The hourly run re-reads the same
 *    `RECENT` window, so without this the same conversation becomes a fresh
 *    signal every hour. Not limited to thirty days — an unexpired unconsumed
 *    signal is by definition still open, and re-adding it would be the
 *    duplicate whatever its age.
 * 3. **Units published in the last thirty days.** The literal sentence of §4.1.
 *    Thirty days and not forever: a subject the site covered last year is fair
 *    game again, which is the same judgement {@see SelectTopics}
 *    makes about the corpus.
 * 4. **The open queue.** Anything not published and not archived — an idea, a
 *    planned unit, a draft, something approved and waiting to go. This is the
 *    half §4.1 emphasises, and the half that is invisible in production until
 *    five drafts of one news item are sitting in front of an operator.
 *
 * Deterministic throughout, and it has to be: this runs against every candidate
 * every hour, and a model call per comparison would cost more than the entire
 * rest of the contour.
 */
class Deduplicate extends AbstractStep
{
    /** §4.1, in days. */
    private const int PUBLISHED_WINDOW_DAYS = 30;

    /** Units that are neither published nor abandoned: the queue of §4.1. */
    private const array QUEUED = [
        ContentItemState::Idea,
        ContentItemState::Queued,
        ContentItemState::Generating,
        ContentItemState::Draft,
        ContentItemState::Approved,
    ];

    public static function key(): string
    {
        return 'deduplicate';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [Normalise::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(Normalise::key())) {
            return StepResult::skip('Nothing was gathered, so there is nothing to deduplicate.');
        }

        $candidates = $context->output(Normalise::key(), CandidatePayload::class)->candidates;

        if ($candidates === []) {
            return StepResult::success(new CandidatePayload([], []));
        }

        $known = [
            ...$this->fingerprintsOfSignals(),
            ...$this->fingerprintsOfUnits(),
        ];

        $kept = [];
        $rejected = [];

        // Which subject each platform id was filed under this hour. The second
        // key on a candidate, and the one the subject key cannot replace: the
        // classification of §4.1 reads a post's subject out of its text, and
        // the same post read twice — once per search mode, once per term that
        // matched it — can come back with two phrasings of one subject. Two
        // rows would then be written for one post, and because they share an
        // external id the second `ON CONFLICT` would overwrite the first rather
        // than being caught by anything. One post is one signal, whatever we
        // called it.
        $identities = [];

        // Which platform ids each surviving subject has already been seen
        // under. The corroboration bonus is paid off this and not off the
        // collision itself: {@see FetchSearch} asks every term in both modes of
        // §4.1 and the terms overlap, so a post matched by two terms, or by one
        // term in both modes, collides with itself several times an hour. Paying
        // for that would mean +10 for reading one post twice, which is not
        // "one subject arriving from two independent sources" — it is the same
        // source, twice.
        //
        // @var array<string, array<string, true>> $arrivals
        $arrivals = [];

        foreach ($candidates as $candidate) {
            $subjectKeys = self::keysFor($candidate->title, $candidate->entities);
            $identity = self::identityOf($candidate->source, $candidate->externalId);

            $seen = array_intersect_key($known, array_flip(
                $identity === null ? $subjectKeys : [...$subjectKeys, $identity],
            ));

            if ($seen !== []) {
                // Recorded once per fingerprint rather than once per arrival:
                // the operator's "чего движок делать не стал и почему" of §7 is
                // asked about the subject, not about each of the four ids it
                // came in under.
                $rejected[$candidate->fingerprint] = (string) reset($seen);

                continue;
            }

            // Grouped on the subject alone rather than on the full fingerprint.
            // The entity set is resolved from the *text*, and the same story in
            // a feed headline and in somebody's post about it does not mention
            // the same words — so the coarser key is the one that actually
            // collapses "один горячий сигнал" into one row, which is the
            // sentence this step exists for. The row still stores the full
            // fingerprint; this only decides what counts as the same subject
            // inside one hour.
            $fingerprint = $subjectKeys === []
                ? $candidate->fingerprint
                : $subjectKeys[array_key_last($subjectKeys)];

            // One post already kept under another subject this hour: it is the
            // same post, so it joins that entry rather than opening a second.
            $fingerprint = ($identity === null ? null : ($identities[$identity] ?? null)) ?? $fingerprint;

            // Asked before the arrival is recorded, because the answer is about
            // the state the incumbent was kept in.
            $reread = $identity !== null && isset($arrivals[$fingerprint][$identity]);

            if ($identity !== null) {
                $identities[$identity] = $fingerprint;
                $arrivals[$fingerprint][$identity] = true;
            }

            if (! isset($kept[$fingerprint])) {
                $kept[$fingerprint] = $candidate;

                continue;
            }

            // The same subject, twice in one hour. Keep the heavier of the two
            // — a question outranks the news item it was asked about.
            $incumbent = $kept[$fingerprint];
            $winner = $candidate->weight > $incumbent->weight ? $candidate : $incumbent;
            $heaviest = max($candidate->weight, $incumbent->weight);

            // And pay the corroboration bonus only when the collision is two
            // posts rather than one post read twice. An arrival with no
            // platform id of its own cannot be shown to be a re-read — the
            // sources that have none are the ones we noticed ourselves (a
            // corpus gap, a seasonal curve), and two of those about one subject
            // really are two observations — so it is treated as an intake.
            $kept[$fingerprint] = $winner->weighing(
                $reread ? $heaviest : SignalWeight::corroborated($heaviest),
            );
        }

        $context->remember('social_listen.deduplicated', count($rejected));

        return StepResult::success(new CandidatePayload(array_values($kept), $rejected));
    }

    /**
     * Subjects this project already holds a live signal for.
     *
     * `scopeLive()` rather than a date window: the scope is the planner's only
     * entry point into the table and it already means "inside its window and
     * not already used", which is exactly the set a new arrival would duplicate.
     * A consumed signal is deliberately not here — it produced something, and
     * whatever it produced is caught by the unit halves below. ⚠️ Which is
     * just as well, because nothing writes `consumed_at` yet ({@see Signal}
     * names the phase that owes the writer), so today `live()` means "inside
     * its window" and the second half of the sentence is aspirational. Nothing
     * here depends on it: the keys below are the subject and the platform id.
     *
     * @return array<string, string>
     */
    private function fingerprintsOfSignals(): array
    {
        $known = [];

        $live = Signal::query()->live()->get(['title', 'entities', 'fingerprint', 'source', 'external_id']);

        foreach ($live as $signal) {
            $reason = 'this project already has an open signal about it';

            // The stored fingerprint first, because it is the authority on what
            // that row is, and the recomputed keys after it, because a row
            // written before the vocabulary grew resolved fewer entities than
            // the same subject would today.
            $known[$signal->fingerprint] = $reason;

            foreach (self::keysFor($signal->title, $signal->entities) as $key) {
                $known[$key] = $reason;
            }

            // And the platform's own id, which catches the case the subject
            // cannot: the hourly run re-reading the same `RECENT` window and
            // the classification phrasing that post's subject slightly
            // differently the second time.
            $identity = self::identityOf($signal->source, $signal->external_id);

            if ($identity !== null) {
                $known[$identity] = 'this project already has a signal for that exact post';
            }
        }

        return $known;
    }

    /**
     * A candidate's identity on the platform it came from, or null when it has
     * none — a corpus gap and a seasonal curve are things we noticed rather
     * than things we were handed, and the migration keeps them out of the
     * unique index for the same reason.
     *
     * **Keyed on the platform and not on the intake.** An id is issued by the
     * platform, and every intake that reads that platform reads the same one:
     * a reply under one of our posts arrives from {@see FetchMentions} as
     * {@see SignalSource::ThreadsWebhook} and from `keyword_search` as
     * {@see SignalSource::ThreadsKeywordSearch} carrying the same `id`. Scoped
     * to the source, those two keys never meet — and neither does anything else
     * catch them, because `signals_external_id_unique` is `(project_id, source,
     * external_id)`, so both rows insert cleanly and one post becomes two
     * signals and potentially two article ideas.
     *
     * Prefixed, so it can share a keyspace with the fingerprints without ever
     * being mistaken for one.
     */
    private static function identityOf(SignalSource $source, ?string $externalId): ?string
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return 'id:'.self::platformOf($source).'|'.$externalId;
    }

    /**
     * Whose id space a source's `external_id` lives in.
     *
     * A `match` rather than a property on {@see SignalSource}, and exhaustive:
     * the enum is about what an intake cost (its own docblock says so), and
     * adding a case there should force whoever adds it to answer this question
     * here rather than inherit an answer. An id space is shared by every intake
     * that reads one platform, and by nothing else — two RSS feeds carrying one
     * story issue two guids, which is precisely why the subject fingerprint
     * exists alongside this.
     */
    private static function platformOf(SignalSource $source): string
    {
        return match ($source) {
            SignalSource::ThreadsKeywordSearch,
            SignalSource::ThreadsWebhook,
            SignalSource::ThreadsInsights => 'threads',
            SignalSource::Rss => 'rss',
            SignalSource::KeywordPool => 'keyword_pool',
            SignalSource::Corpus => 'corpus',
            SignalSource::Business => 'business',
        };
    }

    /**
     * Subjects already published within the window, or sitting in the queue.
     *
     * Both halves in one pass over the units, because both need the same thing
     * — the fingerprint of a unit — and computing it twice would be two
     * traversals of the corpus for one answer.
     *
     * A unit's fingerprint is taken from its `target_query` where it has one
     * and its title otherwise. The query is what the unit is *about*; the title
     * is the writer's phrasing of it, and a unit whose title was rewritten at
     * the end of generation must not stop matching the signal that caused it.
     *
     * @return array<string, string>
     */
    private function fingerprintsOfUnits(): array
    {
        $since = CarbonImmutable::now()->utc()->subDays(self::PUBLISHED_WINDOW_DAYS);

        $units = ContentItem::query()
            ->select(['title', 'target_query', 'entities', 'state', 'published_at'])
            ->where(function ($query) use ($since): void {
                $query
                    ->where(function ($published) use ($since): void {
                        $published
                            ->where('state', ContentItemState::Published->value)
                            // Bound in UTC. `Signal`'s docblock spells out what
                            // a local time in one of these comparisons costs,
                            // and here it would be the thirty-day window of
                            // §4.1 missing a subject by the project's offset.
                            ->where('published_at', '>=', $since);
                    })
                    ->orWhereIn('state', array_map(
                        static fn (ContentItemState $state): string => $state->value,
                        self::QUEUED,
                    ));
            })
            ->get();

        $known = [];

        foreach ($units as $unit) {
            $subject = (string) ($unit->target_query ?? $unit->title);

            if (trim($subject) === '') {
                continue;
            }

            $reason = $unit->state === ContentItemState::Published
                ? 'this project published something about it within the last 30 days'
                : 'this project already has something about it in the queue';

            foreach (self::keysFor($subject, $unit->entities) as $key) {
                $known[$key] = $reason;
            }
        }

        return $known;
    }

    /**
     * The keys one subject is recognised by, coarsest last.
     *
     * Two of them, and the second is what makes the window actually close. The
     * full fingerprint is the subject *and* its resolved entities, which is
     * right for storage and too strict for matching: a headline in a feed
     * resolves against the project's vocabulary differently from a person's
     * post about the same headline, so two rows about one story would carry two
     * fingerprints and both would be written. The subject-only key is the same
     * hash with the entity set empty, and it is what collapses them.
     *
     * Both are produced by {@see Signal::fingerprintFor()} rather than by a
     * second normalisation written here — its Unicode handling and its refusal
     * of an empty subject are the behaviour this depends on, and a second
     * implementation of them would drift.
     *
     * @param  list<string>  $entities
     * @return list<string>
     */
    private static function keysFor(string $subject, array $entities): array
    {
        $keys = [];

        foreach ([$entities, []] as $set) {
            try {
                $keys[] = Signal::fingerprintFor($subject, $set);
            } catch (InvalidArgumentException) {
                // No subject and nothing to stand in for one. Empty rather than
                // a placeholder: the model's own guard says hashing an empty
                // subject would collide every untitled row in the project into
                // one, and a placeholder key here would do exactly that.
            }
        }

        return array_values(array_unique($keys));
    }
}
