<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\PostScore;
use App\Content\UnitScore;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\RejectionReason;
use App\Http\Requests\RejectContentRequest;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;
use App\Pipelines\Core\PipelineRunner;
use App\Publishing\PublishToChannels;
use App\Support\Content\ContentItemProps;
use App\Support\Social\ChannelPayload;
use App\Support\Social\ChannelPayloadSegment;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * The approvals queue — §7 calls it the main daily screen, and the Phase 0 exit
 * criterion ("two projects publish daily, the operator only approves") is only
 * reachable if this takes minutes.
 *
 * So it is ordered by what is most overdue, it shows why a draft might be
 * suspect before the operator opens it, and approving is one request.
 *
 * **Articles and posts, in one queue.** §7 gives the operator one screen and
 * five minutes, and a second queue would be a second habit — the one thing a
 * five-minute routine cannot absorb. So a social draft waits here beside the
 * articles, says on its row that it is a post, and is approved by the same
 * request. What differs is the checklist it is measured against, which
 * {@see UnitScore} picks: an article's critical checks are about a page and
 * would refuse every post ever written, which is how §4.3's whole contour used
 * to dead-end with nothing failing.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly PublishToChannels $channels,
        private readonly UnitScore $score,
        private readonly PipelineRunner $runner,
        private readonly CurrentProject $current,
    ) {}

    public function index(): Response
    {
        $drafts = ContentItem::query()
            // Root articles, and posts of either kind. `roots()` alone is the
            // article half — it excludes both a locale variant and a social
            // post — and a derivative post (§5's Derivative band) has a parent,
            // so neither scope on its own reaches everything that waits on a
            // human. The two together are the partition {@see ContentItem::scopeSocial()}
            // was written to make expressible in SQL.
            ->where(fn (QueryBuilder $query) => $query->roots()->orWhere(
                fn (QueryBuilder $social) => $social->social(),
            ))
            ->inState(ContentItemState::Draft)
            ->with(['localeVariants', 'derivatives', 'assets'])
            ->orderByRaw('scheduled_for is null, scheduled_for asc')
            // Within a day, a post has a time and an article does not. §4.3's
            // presence window is the reason: a slot placed for 09:20 is a
            // commitment to be at a phone at 09:20, so it sorts above the piece
            // that is merely due today.
            ->orderByRaw('slot_at is null, slot_at asc')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('approvals/index', [
            'drafts' => $drafts->through(function (ContentItem $item): array {
                $score = $this->score->for($item);

                return [
                    ...ContentItemProps::summary($item),
                    // Surfaced in the list rather than only on the card: these are
                    // the two reasons an operator would not simply approve, and
                    // finding them one click in is what makes a queue slow.
                    'factcheck_passed' => (bool) ($item->factcheck['passed'] ?? true),
                    'factcheck_findings' => count($item->factcheck['findings'] ?? []),
                    'entity_coverage' => $this->coverage($item),
                    'was_rejected' => $item->reviewed_at !== null,
                    'publishable' => $score['publishable'],
                    'blocking' => $score['blocking'],
                    // What tells a post apart from an article on the row. A
                    // post has no target query, no locale spread and no
                    // derivative count worth printing, and it does have a band,
                    // a segment count and a body short enough to read in the
                    // list — which is the whole reason it can share this queue
                    // rather than needing one of its own.
                    'is_social' => $item->isSocial(),
                    'social_band' => $item->social_band?->value,
                    'social_band_label' => $item->social_band?->label(),
                    'segments' => count($this->segments($item)),
                    'excerpt' => $this->excerpt($item),
                    'slot_at' => $item->slot_at?->toIso8601String(),
                    'expires_at' => $item->expires_at?->toIso8601String(),
                ];
            }),
            'reasons' => array_map(
                static fn (RejectionReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ],
                RejectionReason::cases(),
            ),
        ]);
    }

    public function approve(ContentItem $item): RedirectResponse
    {
        $deliveries = DB::transaction(function () use ($item): array {
            $draft = ContentItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless($draft->state === ContentItemState::Draft, 409, 'Only a draft can be approved.');

            $scored = $this->score->for($draft->loadMissing('assets'));

            if (! $scored['publishable']) {
                throw ValidationException::withMessages([
                    'approval' => 'This draft is not publishable: '.implode(', ', $scored['blocking']).'.',
                ]);
            }

            // The complaint has been answered — somebody accepted the work.
            // Left standing it would be read by every future rewrite
            // ({@see \App\Pipelines\Steps\Generation\CompileBrief}) and shown
            // on the card as an outstanding objection to an article nobody
            // objects to any more.
            $draft->forceFill(['review' => [], 'reviewed_at' => null])->save();

            $draft->approve();

            return $this->channels->publishAutomatically($draft);
        });

        $refusal = $this->channels->refusal($item);

        // Approved either way. The ceiling of §4.3 is about when a post lands,
        // not about whether it was worth approving, and rolling the approval
        // back would make the operator press the button again on Wednesday.
        // What changes is the sentence: §7's last line is mandatory, and
        // "approved." beside a post the engine has quietly decided not to send
        // is the silence that line exists to prevent.
        Inertia::flash('toast', [
            'type' => $refusal === null ? 'success' : 'info',
            'message' => match (true) {
                $refusal !== null => "{$item->title} approved, but not going out yet: {$refusal}.",
                $deliveries !== [] => "{$item->title} approved and queued for automatic publishing.",
                default => "{$item->title} approved.",
            },
        ]);

        return back();
    }

    public function publish(ContentItem $item): RedirectResponse
    {
        abort_unless(
            in_array($item->state, [ContentItemState::Approved, ContentItemState::Published], true),
            409,
            'Only approved or published content can be sent to channels.',
        );

        // Asked before the send rather than inferred from an empty result: a
        // ceiling and a project with no channel connected are different
        // problems with different fixes, and "no verified channel can take
        // this" pointed at a spent daily budget would send the operator to the
        // integrations screen for the rest of the morning.
        $refusal = $this->channels->refusal($item);

        if ($refusal !== null) {
            throw ValidationException::withMessages([
                'publishing' => ucfirst($refusal).'.',
            ]);
        }

        $deliveries = $this->channels->publishManually($item);

        if ($deliveries === []) {
            throw ValidationException::withMessages([
                'publishing' => 'No enabled, verified channel can take this.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$item->title} synchronized across ".count($deliveries).' eligible channel(s).',
        ]);

        return back();
    }

    public function reject(RejectContentRequest $request, ContentItem $item): RedirectResponse
    {
        DB::transaction(function () use ($request, $item): void {
            $draft = ContentItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                in_array($draft->state, [ContentItemState::Draft, ContentItemState::Approved], true),
                409,
                'Only a draft or an approved unit can be sent back.',
            );

            // Approved is the case this screen had no answer for. An article
            // signed off with a fault in it could be published or ignored and
            // nothing else — there was no way back to the queue, because
            // `approved` has one edge and it points at `published`. Taking an
            // approval back is a person's decision, so it is a named method
            // rather than a widening of the state map: see
            // {@see ContentItem::returnForRework()} for why that distinction is
            // worth keeping.
            //
            // A draft that is sent back stays a draft. It is already where
            // rework happens, and the note is the whole of the change.
            if ($draft->state === ContentItemState::Approved) {
                $draft->returnForRework();

                // Approving may already have queued this unit at every channel
                // that publishes automatically, and a delivery carries a payload
                // snapshot it will send whatever the unit has done since. Taking
                // the approval back has to take those with it, or the operator
                // pulls an inaccurate article and the version they pulled goes
                // out a minute later.
                //
                // Dead letter rather than deleted: the row is the record that a
                // publication was intended and stopped, and the operator's
                // delivery screen is where they would look for it. The
                // publishers refuse a withdrawn unit as well
                // ({@see \App\Publishing\Concerns\RecordsDeliveryOutcome::refuseIfWithdrawn()}),
                // which is what covers the one already in flight while this
                // covers the ones still waiting.
                WebhookDelivery::query()
                    ->where('content_item_id', $draft->getKey())
                    ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value])
                    ->update([
                        'status' => DeliveryStatus::DeadLetter->value,
                        'error' => 'Cancelled: the unit was sent back for rework before this went out.',
                        'next_attempt_at' => null,
                    ]);
            }

            $draft->forceFill([
                'review' => [
                    'reason' => $request->string('reason')->value(),
                    'note' => $request->string('note')->value(),
                    'by' => $request->user()?->name,
                    'at' => now()->toIso8601String(),
                ],
                'reviewed_at' => now(),
            ])->save();
        });

        $rewriting = $this->rewrite($item, $request->enum('reason', RejectionReason::class));

        Inertia::flash('toast', [
            'type' => 'info',
            // What happens next, not what just happened. "Sent back" on its own
            // is the message this screen used to give and it left an operator
            // watching an unchanged article wondering whether the button had
            // worked at all.
            'message' => $rewriting
                ? "{$item->title} sent back — the engine is rewriting it."
                : "{$item->title} sent back.",
        ]);

        return back();
    }

    /**
     * Hand the unit back to the engine, and say whether it was taken.
     *
     * Sending something back has to *cause* something. Without this the button
     * un-approved an article and left it word for word as it was: the operator
     * had said what was wrong, in a closed set built for counting, and the only
     * thing that could act on it was a human rewriting by hand — which is the
     * one thing §7's five-minute routine has no room for.
     *
     * Not when the reason points at the brief. {@see RejectionReason::isBriefProblem()}
     * exists for this and says it plainly: a project whose rejections are mostly
     * off-brand has a brief problem, and regenerating the same article from the
     * same brief produces the same article at full price. That one waits for a
     * human to fix the voice it is written from.
     *
     * Articles only. A social post is a slot with a time on it and a TTL that
     * may have passed; rewriting one belongs to §4.3's contour, not to this
     * button.
     *
     * A failure here does not fail the send-back. The unit is already back in
     * the queue with its note, which is the part the operator asked for; a run
     * that will not start is worth a log line and the ordinary tick, not an
     * error on a screen where nothing went wrong.
     */
    private function rewrite(ContentItem $item, RejectionReason $reason): bool
    {
        if ($item->isSocial() || $reason->isBriefProblem()) {
            return false;
        }

        $project = $this->current->get();

        if ($project === null) {
            return false;
        }

        try {
            $this->runner->start('generation', $project, [], $item->getKey());

            return true;
        } catch (Throwable $e) {
            Log::warning('A unit sent back could not be queued for a rewrite', [
                'unit' => $item->slug,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The post as the operator will read it, or null for an article.
     *
     * A post is short enough that the row can hold the whole thing, and a queue
     * that shows an article's title shows a post's text — the title of a social
     * unit is a label the planner wrote, not something anybody is going to
     * publish. Reading it here rather than in the browser keeps the decision
     * ("is this what we want to say") on the same screen as the button.
     */
    private function excerpt(ContentItem $item): ?string
    {
        if (! $item->isSocial()) {
            return null;
        }

        $segments = $this->segments($item);

        return $segments === []
            ? null
            : Str::limit(implode("\n\n", $segments), 400);
    }

    /**
     * §3's segments, as plain strings.
     *
     * A payload that cannot be parsed is read as no payload rather than
     * allowed to throw: one unreadable JSON column would take down the whole
     * queue, and {@see PostScore} already refuses to call such a
     * unit publishable, so the row renders with nothing to read and a blocking
     * reason beside it.
     *
     * @return list<string>
     */
    private function segments(ContentItem $item): array
    {
        $raw = $item->channel_payload;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        try {
            $payload = ChannelPayload::fromArray($raw);
        } catch (InvalidArgumentException) {
            return [];
        }

        return array_map(
            static fn (ChannelPayloadSegment $segment): string => $segment->text,
            $payload->segments,
        );
    }

    private function coverage(ContentItem $item): ?float
    {
        $coverage = $item->entity_coverage;

        if ($coverage === []) {
            return null;
        }

        return round(count(array_filter($coverage)) / count($coverage), 2);
    }
}
