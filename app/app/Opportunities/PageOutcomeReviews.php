<?php

declare(strict_types=1);

namespace App\Opportunities;

use App\Feedback\Followups\CaptureChangeReviews;
use App\Feedback\Followups\ObservationWindow;
use App\Feedback\Followups\RecoveryConfounders;
use App\Models\PageChangeBaseline;
use App\Models\PageChangeFollowup;
use App\Models\PageChangeReview;
use App\Models\PageOpportunity;
use App\Models\PageOutcomeReview;
use App\Models\PageProposal;
use App\Models\PagePublication;
use App\Models\PagePublicationOperation;
use App\Models\SitePage;
use App\Models\User;
use App\Pages\TrackedPages;
use App\Proposals\Proposals;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PageOutcomeReviews
{
    public function __construct(private readonly Proposals $proposals, private readonly CurrentProject $current, private readonly TrackedPages $pages, private readonly DiagnoseOpportunities $diagnosis) {}

    /** @return array<string,mixed> */
    public function context(PagePublication $publication): array
    {
        $periods = [];
        $preferred = null;
        foreach (PageChangeFollowup::query()->where('publication_id', $publication->id)->orderByDesc('days')->get() as $followup) {
            $review = PageChangeReview::query()->where('followup_id', $followup->id)->latest('id')->first();
            $baseline = PageChangeBaseline::query()->findOrFail($followup->baseline_id);
            $periods[] = ['days' => $followup->days, 'review_id' => $review?->id, 'status' => $review->status ?? 'observing', 'due_on' => $followup->due_on->toDateString(), 'captured_at' => $review?->captured_at->toIso8601String(),
                'baseline' => $baseline->evidence['periods'][$followup->days] ?? null, 'review' => $review?->evidence,
                'current_recoveries' => app(RecoveryConfounders::class)->within($followup->site_page_id, $baseline->pinned_at, CarbonImmutable::parse($followup->window_to->toDateString(), ObservationWindow::ZONE)->addDay()->utc())];
            $preferred ??= $review?->id;
        }
        $history = PageOutcomeReview::query()->where('publication_id', $publication->id)->latest('id')->get();

        return ['publication_id' => $publication->id, 'verified' => $publication->verified_at !== null, 'recovered_at' => $publication->recovered_at?->toIso8601String(),
            'latest_id' => $history->first()?->id, 'measurement_review_id' => $preferred, 'periods' => $periods,
            'history' => $history->map(fn (PageOutcomeReview $review): array => ['id' => $review->id, 'decision' => $review->decision, 'reason' => $review->reason,
                'next_opportunity_id' => $review->next_opportunity_id, 'result' => $review->evidence['result'] ?? null, 'created_at' => $review->created_at->toIso8601String()])->all()];
    }

    /** @param array<string,mixed> $input */
    public function record(PagePublication $publication, User $actor, array $input): PageOutcomeReview
    {
        $this->proposals->owner($actor);
        abort_unless($publication->project_id === $this->current->id(), 404);
        $reassess = $input['decision'] === 'reassess';
        if ($reassess) {
            abort_unless($publication->page !== null, 404);
            $this->pages->capture($this->current->get(), $publication->page);
            if (isset($input['measurement_review_id'])) {
                $selected = PageChangeReview::query()->findOrFail((string) $input['measurement_review_id']);
                $followup = PageChangeFollowup::query()->where('publication_id', $publication->id)->findOrFail($selected->followup_id);
                app(CaptureChangeReviews::class)->capture($followup);
            }
        }

        return DB::transaction(function () use ($publication, $actor, $input, $reassess): PageOutcomeReview {
            $this->proposals->lockProject();
            $publication = PagePublication::query()->lockForUpdate()->findOrFail($publication->id);
            abort_unless($publication->verified_at !== null, 409, 'Verify the public change before reviewing its outcome.');
            $context = $this->context($publication);
            if ($context['latest_id'] !== ($input['expected_outcome_id'] ?? null) || $context['measurement_review_id'] !== ($input['measurement_review_id'] ?? null)) {
                throw ValidationException::withMessages(['outcome' => 'The outcome or measured evidence changed. Reload and review it before recording a decision.']);
            }
            $page = SitePage::query()->lockForUpdate()->findOrFail($publication->site_page_id);
            $snapshot = $page->latestSnapshot;
            abort_unless($snapshot !== null, 409, 'Capture the public page first.');
            $reviewId = (string) Str::ulid();
            $next = null;
            $result = $reassess ? 'No justified next change was found in the current evidence.' : 'Owner decision recorded; no new work was generated.';
            if ($reassess) {
                if ($context['measurement_review_id'] === null) {
                    throw ValidationException::withMessages(['outcome' => 'Wait for a settled 14-day or 28-day observation and read its data before requesting reassessment.']);
                }
                abort_unless($page->tracked_at !== null && $snapshot->captured_at->greaterThanOrEqualTo(now()->subDays(30)), 409, 'Track and refresh this page before reassessment.');
                $outstanding = PagePublicationOperation::query()->where('site_page_id', $page->id)->whereIn('status', ['queued', 'sending', 'outcome_unknown', 'blocked_unresolved'])->exists();
                $otherWork = PageProposal::query()->where('site_page_id', $page->id)->where(fn ($query) => $query->whereKeyNot($publication->proposal_id)->orWhere('current_revision_id', '!=', $publication->revision_id))->whereIn('status', ['drafting', 'review_required', 'approved', 'failed'])
                    ->whereDoesntHave('publications', fn ($query) => $query->whereNotNull('verified_at')->whereColumn('page_publications.revision_id', 'page_proposals.current_revision_id'))->exists();
                $laterPublication = PagePublication::query()->where('site_page_id', $page->id)->whereKeyNot($publication->id)->where('authorized_at', '>', $publication->authorized_at)->exists();
                $open = PageOpportunity::query()->where('site_page_id', $page->id)->where('status', 'open')->exists();
                if ($outstanding || $otherWork || $open || $laterPublication) {
                    throw ValidationException::withMessages(['outcome' => 'Finish the current page work or resolve its delivery before starting another cycle.']);
                }
                $candidate = $this->diagnosis->forOutcome($page, ['id' => $reviewId, 'publication_id' => $publication->id, 'reason' => $input['reason'], 'periods' => $context['periods']]);
                if ($candidate !== null) {
                    $next = PageOpportunity::query()->create($candidate);
                    $result = 'A new bounded opportunity is ready in Plan. It still needs a proposal, review and separate publication permission.';
                }
            }

            return PageOutcomeReview::query()->create(['id' => $reviewId, 'publication_id' => $publication->id, 'site_page_id' => $page->id,
                'actor_id' => $actor->id, 'decision' => $input['decision'], 'reason' => $input['reason'], 'source_snapshot_id' => $snapshot->id,
                'next_opportunity_id' => $next?->id, 'evidence' => ['periods' => $context['periods'], 'measurement_review_id' => $context['measurement_review_id'], 'result' => $result,
                    'recovered_at' => $publication->recovered_at?->toIso8601String(), 'limitations' => ['Observed changes are associations, not proof of incremental sales. A dip alone never authorizes rewriting.']]]);
        });
    }
}
