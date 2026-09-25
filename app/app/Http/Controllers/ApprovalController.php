<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\ArticleScore;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\RejectionReason;
use App\Http\Requests\RejectContentRequest;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;
use App\Pipelines\Core\PipelineRunner;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\PublishToChannels;
use App\Support\Content\ContentItemProps;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The approvals queue — §7 calls it the main daily screen, and the Phase 0 exit
 * criterion ("two projects publish daily, the operator only approves") is only
 * reachable if this takes minutes.
 *
 * So it is ordered by what is most overdue, it shows why a draft might be
 * suspect before the operator opens it, and approving is one request.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly PublishToChannels $channels,
        private readonly ArticleScore $score,
        private readonly PipelineRunner $runner,
        private readonly CurrentProject $current,
    ) {}

    public function index(): Response
    {
        $drafts = ContentItem::query()
            ->inState(ContentItemState::Draft)
            ->with(['localeVariants', 'assets'])
            ->orderByRaw('scheduled_for is null, scheduled_for asc')
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
        abort_unless(in_array($item->state, [ContentItemState::Draft, ContentItemState::Approved], true), 409, 'Only a finished draft can be approved.');
        try {
            app(ArticleApproval::class)->approve($item);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['article_allowance'])) {
                abort(409, $exception->getMessage());
            }
            throw $exception;
        }
        $deliveries = app(ArticleSchedules::class)->dispatch($item);
        $item->loadMissing('articleSchedule');
        Inertia::flash('toast', ['type' => 'success', 'message' => $deliveries === []
            ? ($item->articleSchedule === null ? 'Article approved. Choose a publication time or publish it now.' : 'Article approved. It will publish at its scheduled time.') : 'Article approved and queued for publishing.']);

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
        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->first();
        if ($schedule !== null && ! in_array($schedule->status, ['paused', 'canceled', 'completed'], true)) {
            app(ArticleSchedules::class)->change($item, $schedule->version, 'pause');
        }

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
     * A failure here does not fail the send-back. The unit is already back in
     * the queue with its note, which is the part the operator asked for; a run
     * that will not start is worth a log line and the ordinary tick, not an
     * error on a screen where nothing went wrong.
     */
    private function rewrite(ContentItem $item, RejectionReason $reason): bool
    {
        if ($reason->isBriefProblem()) {
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

    private function coverage(ContentItem $item): ?float
    {
        $coverage = $item->entity_coverage;

        if ($coverage === []) {
            return null;
        }

        return round(count(array_filter($coverage)) / count($coverage), 2);
    }
}
