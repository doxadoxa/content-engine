<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Enums\AssetRole;
use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\ContentItemType;
use App\Enums\PostKind;
use App\Enums\SlideLayout;
use App\Enums\SocialKpi;
use App\Media\CarouselPanels;
use App\Media\HeroImage;
use App\Media\SocialImage;
use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\ContentGoal;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\SitePage;
use App\Onboarding\ProjectLaunch;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\SocialDraft\DraftCandidates;
use App\Pipelines\Steps\SocialDraft\FactCheckPost;
use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Social\PublishedCadence;
use App\Support\Brand\VisualStyle;
use App\Support\Corpus\SiteLibrary;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ChannelPostScore;
use App\Support\Social\ContentMix;
use App\Support\Social\PostFormat;
use App\Support\Social\SocialImagePrompt;
use App\Support\Social\StudioPostGuard;
use App\Support\Social\VisualBriefGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proposal-first interface over the content engine.
 *
 * Model output never writes directly to a publish adapter. A proposal becomes
 * immutable `ContentIdea` rows, accepting it records an explicit version, and
 * generation stops at reviewable `ContentItem::Draft` rows.
 */
class ContentStudioAssistant
{
    private const int PROPOSAL_LOCK_SECONDS = 300;

    private const int DRAFT_LOCK_SECONDS = 900;

    private const int DUPLICATE_WAIT_SECONDS = 120;

    /**
     * How far down the ranking §10's fact check is willing to walk.
     *
     * Two, which buys the common case — one candidate invented a number, the
     * runner-up did not — without turning a YMYL project's batch into one model
     * call per candidate per channel.
     */
    private const int FACT_CHECKS = 2;

    /**
     * How many times a month may be asked for before it is taken as it is.
     *
     * Two. The first ask carries the mix as an instruction; the second carries
     * the model's own month back to it with what is wrong. A third would be
     * paying full price for a plan an operator is about to edit anyway.
     */
    private const int PROPOSAL_ATTEMPTS = 2;

    /** @var list<string> */
    private const array CHANNELS = [
        ChannelType::Threads->value,
        ChannelType::X->value,
        ChannelType::Instagram->value,
    ];

    /** Pages read inside a proposal that found no corpus. See {@see ensureFacts()}. */
    private const int FACTS_ON_DEMAND = 20;

    public function __construct(
        private readonly SocialImage $images,
        private readonly SiteLibrary $library,
    ) {}

    public function initialProposal(
        Project $project,
        Carbon $month,
        ModelSession $models,
        string $operationId,
    ): ContentPlan {
        $plan = ContentPlan::query()->firstOrCreate(
            ['month' => $month->copy()->startOfMonth()],
        );

        if ($plan->assistant_version > 0) {
            return $plan;
        }

        try {
            return Cache::lock($this->lockKey($plan), self::PROPOSAL_LOCK_SECONDS)
                ->block(self::DUPLICATE_WAIT_SECONDS, function () use (
                    $project,
                    $plan,
                    $models,
                    $operationId,
                ): ContentPlan {
                    $fresh = ContentPlan::query()->whereKey($plan->getKey())->firstOrFail();

                    if ($fresh->assistant_version > 0) {
                        return $fresh;
                    }

                    return $this->buildProposal($project, $fresh, null, 0, $models, $operationId);
                });
        } catch (LockTimeoutException $e) {
            throw new ContentStudioException(
                'The assistant is still building this proposal. Give it a moment and reload.',
                previous: $e,
            );
        }
    }

    public function refine(
        ContentPlan $plan,
        string $message,
        int $expectedVersion,
        ModelSession $models,
        string $operationId,
    ): ContentPlan {
        $message = trim($message);

        if ($message === '') {
            throw new ContentStudioException('Tell the assistant what you want to change.');
        }

        try {
            return Cache::lock($this->lockKey($plan), self::PROPOSAL_LOCK_SECONDS)
                ->block(self::DUPLICATE_WAIT_SECONDS, function () use (
                    $plan,
                    $message,
                    $expectedVersion,
                    $models,
                    $operationId,
                ): ContentPlan {
                    $fresh = ContentPlan::query()->whereKey($plan->getKey())->firstOrFail();

                    if ($this->operationApplied($fresh, $operationId)) {
                        return $fresh;
                    }

                    $this->assertVersion($fresh, $expectedVersion);

                    return $this->buildProposal(
                        $fresh->project,
                        $fresh,
                        $message,
                        $expectedVersion,
                        $models,
                        $operationId,
                    );
                });
        } catch (LockTimeoutException $e) {
            throw new ContentStudioException(
                'The assistant is still updating this proposal. Give it a moment and reload.',
                previous: $e,
            );
        }
    }

    public function accept(ContentPlan $plan, int $expectedVersion): ContentPlan
    {
        return DB::transaction(function () use ($plan, $expectedVersion): ContentPlan {
            $locked = ContentPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $expectedVersion);

            if ($locked->assistant_version === 0) {
                throw new ContentStudioException('There is no assistant proposal to accept yet.');
            }

            // One click, two rows. The proposal and the goal it was written
            // against are one decision to the person making it — "yes, this is
            // the month" — and splitting them into two buttons produced the
            // state nobody could explain: an accepted plan with no goal above
            // it, so the Overview reported progress against a denominator the
            // plan had already argued for and nobody had agreed to.
            //
            // Still two rows underneath, because a goal outlives the proposal
            // that suggested it. Confirmed only when unconfirmed, so accepting a
            // revised proposal never restamps a decision made weeks ago.
            $goal = ContentGoal::forMonth($locked->month);

            if ($goal !== null && ! $goal->isConfirmed()) {
                $goal->forceFill(['confirmed_at' => now()])->save();
            }

            $locked->forceFill([
                'assistant_accepted_version' => $locked->assistant_version,
                'assistant_accepted_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Which ideas the next batch holds, without writing anything.
     *
     * The read half of what used to be one method. Splitting it is the whole of
     * this change: the caller asks what is next, starts a run per idea, and no
     * single job is responsible for a whole week any more. A week's duration
     * used to be the job's duration — four ideas measured at 499 seconds — so
     * the deadline was a function of how full the plan was, and a fuller plan
     * would eventually walk into it.
     *
     * No lock and no model call. Two callers asking at once get the same answer
     * and start the same runs, which the dispatch guard in
     * {@see ContentStudioOperations} collapses by idea.
     *
     * @return array{ideas: Collection<int, ContentIdea>, from: string|null, until: string|null}
     */
    public function nextBatch(ContentPlan $plan, bool $initial = false): array
    {
        if (! $initial && ! $plan->hasAcceptedAssistantVersion()) {
            throw new ContentStudioException('Accept the current proposal before generating drafts.');
        }

        $ideas = $this->ideasMissingDrafts($plan);

        if ($ideas->isEmpty()) {
            return ['ideas' => $ideas, 'from' => null, 'until' => null];
        }

        /** @var ContentIdea $first */
        $first = $ideas->first();
        $from = $first->scheduled_for->copy()->startOfDay();
        $until = $from->copy()->addDays(6)->endOfDay();

        return [
            // Onboarding wants one idea to show, not a week of them: the point
            // of that preview is that something appears quickly.
            'ideas' => $initial
                ? $ideas->take(1)->values()
                : $ideas->filter(
                    static fn (ContentIdea $idea): bool => $idea->scheduled_for->betweenIncluded($from, $until),
                )->values(),
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
        ];
    }

    /**
     * Draft every channel of one idea.
     *
     * The write half, and the unit a run is measured in now. Locked per idea
     * rather than per plan, because two ideas of the same week write different
     * rows and have no reason to wait for each other — the thing worth
     * preventing is the same idea twice, which this still prevents.
     *
     * Idempotent by the same rule it always used: a channel that already has a
     * draft is skipped, so a retry finishes what its predecessor started rather
     * than duplicating it.
     *
     * @return array{created: int, idea: string}
     */
    public function generateIdea(
        ContentIdea $idea,
        ModelSession $models,
        string $operationId,
        ?string $signalId = null,
    ): array {
        try {
            return Cache::lock("content-studio:idea:{$idea->getKey()}", self::DRAFT_LOCK_SECONDS)
                ->block(self::DUPLICATE_WAIT_SECONDS, function () use ($idea, $models, $operationId, $signalId): array {
                    $fresh = ContentIdea::query()->whereKey($idea->getKey())->firstOrFail();
                    $plan = $fresh->contentPlan;

                    $missing = array_values(array_diff(
                        $fresh->channels,
                        ContentItem::query()
                            ->where('content_idea_id', $fresh->getKey())
                            ->pluck('channel_type')
                            ->all(),
                    ));

                    if ($missing === []) {
                        return ['created' => 0, 'idea' => $fresh->idea_key];
                    }

                    $brief = BrandBrief::activeFor($plan->project);

                    $written = $this->persist(
                        $plan,
                        $fresh,
                        $this->draftIdea($plan, $fresh, $missing, $models),
                        $brief,
                        $operationId,
                        $signalId,
                    );

                    $this->illustrateDrafts($written, $brief, $models);

                    return ['created' => count($written), 'idea' => $fresh->idea_key];
                });
        } catch (LockTimeoutException $e) {
            throw new ContentStudioException(
                'This idea is already being drafted. Give it a moment and reload.',
                previous: $e,
            );
        }
    }

    /**
     * Draw this draft another set of pictures, optionally after being told why.
     *
     * The Studio could produce a picture and never reconsider it: the first one
     * a draft got was the one it kept, because {@see SocialImage::for()} returns
     * an existing asset rather than paying twice. That is right for a batch and
     * wrong for a review screen, where the whole point is that a person is
     * looking at the thing and may disagree.
     *
     * The instruction revises the six stored fields rather than being appended
     * to a prompt — see {@see VisualDirector} for why that distinction is the
     * whole design — and the revised fields are what gets stored, so the next
     * round starts from where this one ended rather than from the original.
     *
     * Nothing here changes what the post currently ships. The candidates are
     * variants until somebody picks one.
     *
     * **A carousel's slides are redrawn too, and used not to be.** This bought
     * photograph variants and stopped, so on a seven-slide post "change the
     * picture" changed one of seven — and it changed the one least able to
     * answer what was usually being asked. The slides are the only assets that
     * read the brief's colours ({@see CarouselPanels} draws them from
     * {@see VisualStyle}); the photograph is a generated image where the brief
     * barely reaches the prompt. So "use the fresh colours from the brief"
     * landed on the single asset it could not affect, and left six showing a
     * palette four brief versions old.
     *
     * They are redrawn from the project's *active* brief rather than the
     * version the item is pinned to, which is the same choice the photograph
     * below already makes. The pin is what the post was written from and stays
     * true of its words; an operator asking for a redraw today is asking for
     * today's brand, and a redraw that returned the retired palette would be
     * indistinguishable from this bug.
     *
     * Free, and idempotent: panels render locally rather than being bought, and
     * {@see CarouselPanels} supersedes rather than duplicates — "a redrawn panel
     * is not an alternative anybody chooses between, it is simply the old slide
     * two." Which is also why they are not variants: there is nothing to choose.
     *
     * @return array{variants: int, cost: int, panels: int}
     */
    public function reviseImage(
        ContentItem $item,
        ?string $instruction,
        int $variants,
        ModelSession $models,
    ): array {
        if (! $this->images->isConfigured()) {
            throw new ContentStudioException('No image provider is configured for this project.');
        }

        $payload = $item->channel_payload ?? [];
        $channel = ChannelType::tryFrom((string) $item->channel_type);

        if ($channel === null) {
            throw new ContentStudioException('That draft is not a social post, so it has no channel to draw for.');
        }

        $playbook = ChannelPlaybook::for($channel);

        $fields = is_array($payload['visual'] ?? null) && $payload['visual'] !== []
            ? SocialImagePrompt::fromFields($payload['visual'], $this->imageSubject($item))->toFields()
            : SocialImagePrompt::fromBrief(null, $this->imageSubject($item))->toFields();

        if ($instruction !== null && trim($instruction) !== '') {
            $fields = app(VisualDirector::class)->revise($fields, $instruction, $models);

            // The note is kept beside the brief it produced. §7's rule about a
            // silent machine applies to a person's own edits most of all: an
            // operator returning to a draft has to be able to see what they
            // already asked for, or they ask for it again.
            $payload['visual_notes'][] = [
                'said' => trim($instruction),
                'at' => now()->toIso8601String(),
            ];
        }

        $payload['visual'] = $fields;

        // Read once and used twice, which is also the statement that both
        // halves of this post are drawn from the same brand.
        $brief = BrandBrief::activeFor($item->project);

        $made = $this->images->variants(
            $item,
            $playbook,
            SocialImagePrompt::fromFields($fields, $this->imageSubject($item)),
            $variants,
            $brief,
            $item->contentIdea?->kind->shot(),
        );

        foreach ($made as $picture) {
            $models->spend($picture['cost'], $picture['provider'], $picture['model']);
        }

        $item->forceFill(['channel_payload' => $payload])->save();

        // After the save, so the panels are drawn against the row as it now
        // stands rather than as it was found. Silent on a post with no slides —
        // `drawPanels()` returns on an empty list, which is every post that is
        // a photograph and a caption.
        $this->drawPanels($item, $playbook, $payload, $brief);

        return [
            'variants' => count($made),
            'cost' => array_sum(array_column($made, 'cost')),
            'panels' => is_array($payload['slides'] ?? null) ? count($payload['slides']) : 0,
        ];
    }

    /**
     * Make one of a draft's candidates the picture it ships.
     *
     * No model call and no queue: the decision has already been made by the
     * person clicking, and putting it on the expensive queue would mean an
     * operator waiting on a worker to record a choice they have made.
     */
    public function chooseImage(ContentItem $item, Asset $variant): Asset
    {
        if ((string) $variant->content_item_id !== (string) $item->getKey()) {
            throw new ContentStudioException('That picture belongs to a different draft.');
        }

        // Ownership was the only check, and it is not enough. A carousel's
        // panels belong to the same draft, so posting a slide's id promoted it
        // to hero: the post lost the picture it shipped, the carousel lost a
        // step out of the middle of its sequence, and both roles ended up
        // describing something they are not. The hero itself is allowed through
        // so that choosing what is already chosen is a no-op rather than an
        // error — a double click is not a malformed request.
        if (! in_array($variant->role, [AssetRole::Variant, AssetRole::Hero], true)) {
            throw new ContentStudioException('That picture is part of the post rather than a choice for it.');
        }

        $chosen = $this->images->choose($item, $variant);

        $payload = $item->channel_payload ?? [];
        $payload['asset_id'] = (string) $chosen->getKey();

        if (isset($payload['segments'][0]) && is_array($payload['segments'][0])) {
            $payload['segments'][0]['asset_id'] = (string) $chosen->getKey();
        }

        $item->forceFill(['channel_payload' => $payload])->save();

        return $chosen;
    }

    private function buildProposal(
        Project $project,
        ContentPlan $plan,
        ?string $message,
        int $expectedVersion,
        ModelSession $models,
        string $operationId,
    ): ContentPlan {
        // Asked twice at most, the second time with what was wrong with the
        // first. A month is refused for two things and both are properties of
        // the whole set — selling past the ceiling, and being entirely one kind
        // — so neither can be prevented by a better instruction alone: the
        // model has to see its own month. This is the same correction loop
        // {@see writePool()} runs on a candidate, for the same reason.
        $this->ensureFacts($project, $models);

        $correction = null;
        $proposal = null;
        $findings = [];

        for ($attempt = 1; $attempt <= self::PROPOSAL_ATTEMPTS; $attempt++) {
            $answer = $models->send(new ModelRequest(
                role: 'outline',
                instructions: $this->proposalInstructions(),
                prompt: $this->proposalPrompt($project, $plan, $message, $correction),
            ));

            $proposal = $this->normaliseProposal($answer->text, $plan->month);
            // Measured on the merged month rather than on the model's answer.
            // preserveDraftedIdeas() drops proposed ideas and appends frozen
            // ones, so judging the raw answer described a month that is not the
            // one being stored — a banner reading "no take at all" over a month
            // that has one, and silence over a merged month past the ceiling.
            $proposal['ideas'] = $this->preserveDraftedIdeas($plan, $proposal['ideas']);
            $findings = ContentMix::fromConfig()->findings(array_map(
                static fn (array $idea): PostKind => $idea['kind'],
                $proposal['ideas'],
            ));

            if ($findings === []) {
                break;
            }

            $correction = implode(' ', $findings);
        }

        /** @var array{summary: string, goal: array{kpi: SocialKpi, target: int, cadence: int, weeks: list<array{objective: string}>}|null, strategy: array<string, mixed>, ideas: list<array<string, mixed>>} $proposal */
        // A month that is still unbalanced after the second ask is proposed
        // anyway, with what is wrong with it written down beside it. The
        // alternative is an operator who clicks Propose and gets nothing, and
        // this screen exists so that a person decides — an imbalance they can
        // see and refine is worth more than a refusal they cannot act on.
        $proposal['strategy']['mix_findings'] = $findings;

        return DB::transaction(function () use (
            $plan,
            $proposal,
            $message,
            $expectedVersion,
            $operationId,
        ): ContentPlan {
            $locked = ContentPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $expectedVersion);
            $nextVersion = $expectedVersion + 1;

            $this->storeGoal($locked, $proposal['goal']);

            $locked->forceFill([
                'assistant_summary' => $proposal['summary'],
                'assistant_strategy' => $proposal['strategy'],
                'assistant_version' => $nextVersion,
                // A changed proposal needs a fresh explicit acceptance. The
                // old accepted version remains visible in its ideas/drafts.
                'assistant_accepted_version' => null,
                'assistant_accepted_at' => null,
                'assistant_proposed_at' => now(),
            ])->save();

            foreach ($proposal['ideas'] as $idea) {
                $locked->contentIdeas()->create([
                    ...$idea,
                    'proposal_version' => $nextVersion,
                ]);
            }

            if ($message !== null) {
                $locked->messages()->create([
                    'proposal_version' => $nextVersion,
                    'role' => 'user',
                    'body' => $message,
                    'metadata' => ['pipeline_run_id' => $operationId],
                ]);
            }

            $locked->messages()->create([
                'proposal_version' => $nextVersion,
                'role' => 'assistant',
                'body' => $proposal['summary'],
                'metadata' => [
                    'questions' => $proposal['strategy']['questions'],
                    'idea_count' => count($proposal['ideas']),
                    'pipeline_run_id' => $operationId,
                ],
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Write the proposed goal, unless a person already decided this month's.
     *
     * **A confirmed goal is never overwritten**, which is the rule the goals
     * table was split off `content_plans` to make possible: a plan is replaced
     * every time the operator asks for a better month, and if the goal went with
     * it then asking for better ideas would silently rewrite what the month was
     * for. So refinement can propose ideas against a confirmed target but not
     * move the target — to change that, the operator changes it.
     *
     * Written unconfirmed. Approving the proposal is what confirms it, so the
     * two halves of one decision stay one click; see {@see accept()}.
     *
     * @param  array{kpi: SocialKpi, target: int, cadence: int, weeks: list<array{objective: string}>}|null  $goal
     */
    private function storeGoal(ContentPlan $plan, ?array $goal): void
    {
        if ($goal === null) {
            return;
        }

        $existing = ContentGoal::forMonth($plan->month);

        if ($existing?->isConfirmed() === true) {
            return;
        }

        ContentGoal::query()->updateOrCreate(
            ['month' => $plan->month->copy()->startOfMonth()],
            [
                'kpi' => $goal['kpi'],
                'target' => $goal['target'],
                'cadence' => $goal['cadence'],
                'weeks' => $goal['weeks'],
                'confirmed_at' => null,
            ],
        );
    }

    private function operationApplied(ContentPlan $plan, string $operationId): bool
    {
        return $plan->messages()
            ->where('metadata->pipeline_run_id', $operationId)
            ->exists();
    }

    private function proposalInstructions(): string
    {
        return implode("\n", [
            'You are the content strategist inside a structured content engine.',
            'Make the first useful move. Read the supplied site and brand context, then propose a month instead of asking a blank set of questions.',
            'Separate facts found in the supplied context from assumptions. Never invent a customer, result, price, statistic, launch, or personal experience.',

            // The rule that turns "never invent" from a gag into an
            // instruction. Told only what it may not do, and handed a list of
            // its own article titles, the planner wrote evidence like
            // "Cleaning Point has an article titled X" — a sitemap presented as
            // fact, and the reason a month of posts said nothing.
            'Your facts come from `what_the_business_says_about_itself`: the pages where this business '
                .'states what it sells, for how much, in what time, and what is included. Quote what is '
                .'specific there — a price, a duration, a material, a room, an inclusion, an area '
                .'covered — and put it in the idea\'s `evidence`. An idea whose evidence names something '
                .'checkable is worth ten that do not.',
            'Two things are not evidence and may not be written as any. That this business has published '
                .'an article, or that its site covers a subject — a list of titles is a sitemap, not a '
                .'fact about cleaning, plumbing or software. And anything from those articles '
                .'themselves: they are opinions this business published, and increasingly they are '
                .'written by this engine, so citing one is citing us. `existing_site_articles` is there '
                .'to tell you what has already been covered, and for nothing else.',
            'Where the business says nothing checkable about a subject, say so by choosing a different '
                .'subject. Do not reach for the framing instead — a post whose only content is a way of '
                .'looking at something is the post this instruction exists to prevent.',
            'Threads should invite a real conversation. X should make a compact, useful argument. Instagram should have a visual reason to exist.',

            // The kinds, and the reason they are the first thing decided about
            // an idea rather than a label attached to a finished one. A plan
            // written without them comes back as twenty how-tos every time —
            // which is what it did — because "useful" is the safest thing a
            // model can be, and the channels do not reward safe.
            'Every idea has a kind, and the kind is a decision you make before the subject, not a label '
                .'you add after. The kinds:',
            // Derived, because this list was typed out here and went stale the
            // first time a case was added: `life` reached the planner only as a
            // token inside the mix arithmetic — "about 3 life" — with nothing
            // anywhere saying what one is, and the planner reasonably wrote a
            // month of the five it had been told about, twice, through the
            // correction loop.
            ...PostKind::vocabulary(),

            // The channel rule. An idea that goes everywhere is an idea written
            // once and trimmed twice, which is the cross-posting this whole
            // engine argues against — arranged at the planning step, where no
            // amount of care in the drafting step can undo it.
            'The kind decides where the idea belongs, and no idea goes to all three channels. '
                .PostKind::routing()
                .' Give the channels of the kind you chose, and pick a kind because the idea is that, '
                .'not because you wanted the channels.',

            'Write every idea angle for the channels it is actually going to. Give each channel a distinct '
                .'execution, not one shared paragraph.',

            // The goal, and why the model is asked for it rather than the
            // operator. A blank target field is a question nobody can answer on
            // their first month — the honest answer is "I don't know, what is
            // realistic?" — so it was answered with whatever the field was
            // prefilled with, and a month was then measured against a number
            // that meant nothing. The model has the audience size, the history
            // and the cadence in front of it, which is everything the estimate
            // needs.
            'Also propose what the month is *for*: one KPI, a target, a weekly cadence, and one objective per '
                .'week. Pick the single KPI the month can actually move, not the most impressive one.',
            'Size the target from the supplied audience and history, not from ambition. A target reachable at '
                .'the cadence you are proposing is the point; a round number nobody hits teaches the operator '
                .'to ignore the whole screen. If the account is starting from nothing, propose a small first '
                .'milestone that proves the format works.',
            'Write `expected_impact` as one sentence naming the cadence, the mechanism and the arithmetic that '
                .'gets from where the account is now to the target. It is the operator\'s only way to judge '
                .'whether the plan is plausible before approving it, so it must be falsifiable, not encouraging.',
            'Give exactly four weekly objectives, in order, each a short line describing what that week is '
                .'trying to learn or prove.',
            'If `confirmed_goal` is supplied, the operator has already decided the KPI and the target. Plan the '
                .'month against it and repeat it back unchanged.',

            'Return JSON only, with exactly this shape:',
            // The kind alternation is derived for the reason the vocabulary above
            // is: this enumeration was the third place the five kinds were
            // typed out, and a model handed a mix asking for a kind its output
            // contract forbids resolves the contradiction in favour of the
            // contract. It happened to comply anyway on the run that added
            // `life`, which is worse than failing — the drift was invisible.
            '{"summary":"...","goal":{"kpi":"followers|reach|engagement","target":123,"cadence":3,"expected_impact":"...","weeks":["...","...","...","..."]},"site_facts":[{"claim":"...","source":"site analysis|brand brief|site corpus|business data"}],"assumptions":["..."],"objectives":["..."],"pillars":[{"name":"...","purpose":"..."}],"channel_roles":{"threads":"...","x":"...","instagram":"..."},"questions":["..."],"ideas":[{"key":"short-key","date":"YYYY-MM-DD","title":"...","kind":"'.implode('|', PostKind::values()).'","pillar":"...","thesis":"...","evidence":["..."],"goal":"...","audience":"...","angle":"...","channels":["threads","x"]}]}',
            'Use only dates inside the requested month. Spread the ideas across the month. Keep questions to the two or three unknowns that would materially change the plan.',
        ]);
    }

    private function proposalPrompt(
        Project $project,
        ContentPlan $plan,
        ?string $message,
        ?string $correction = null,
    ): string {
        $brief = BrandBrief::activeFor($project);
        $pages = SitePage::query()->articles()->orderByDesc('published_at')->limit(30)->get([
            'url',
            'title',
            'description',
            'published_at',
        ])->map(static fn (SitePage $page): array => [
            'url' => $page->url,
            'title' => $page->title,
            'description' => $page->description,
            'published_at' => $page->published_at?->toDateString(),
        ])->all();

        $planned = ContentItem::query()
            ->where('content_plan_id', $plan->getKey())
            ->limit(50)
            ->pluck('title')
            ->all();

        $targetIdeas = max(8, min(20, $project->weekly_target * 4));

        // The goal only if a person confirmed it. An unconfirmed one is this
        // assistant's own previous guess, and feeding a model its own estimate
        // back as context is how a made-up number becomes a fact by the third
        // refinement.
        $goal = ContentGoal::forMonth($plan->month);
        $published = PublishedCadence::beforeMonth($plan->month);

        $context = [
            'requested_month' => $plan->month->format('Y-m'),
            'target_ideas' => $targetIdeas,
            // What the account is starting from, so the target is sized against
            // something. Without it the model has no scale and reaches for a
            // round number, which is the failure the operator's own blank
            // target field had.
            'posts_published_in_the_four_weeks_before' => $published,
            'current_posts_per_week' => PublishedCadence::weeklyRate($published),
            'confirmed_goal' => $goal !== null && $goal->isConfirmed() ? [
                'kpi' => $goal->kpi->value,
                'target' => $goal->target,
                'cadence' => $goal->cadence,
            ] : null,
            // Counts rather than percentages, because the model is producing a
            // list of exactly this length and a share has to be converted
            // before it can be obeyed — a conversion it does badly and quietly.
            'required_mix' => ContentMix::fromConfig()->instruction($targetIdeas),
            'what_was_wrong_with_your_last_attempt' => $correction,
            'website_url' => $project->website_url,
            'site_analysis' => $project->site_analysis,
            'brand_brief' => $brief?->compileToPrompt(),
            'original_business_data' => $project->original_data,
            'existing_site_articles' => $pages,
            // What the business says about itself, in its own words, from the
            // pages where it states its offer. The planner had none of this: it
            // was given article titles and a 1.2 KB self-description, told
            // never to invent a fact, and asked for thirty posts — so it wrote
            // "Cleaning Point has an article titled X" and called that
            // evidence. See product/read-what-the-business-says-spec.md.
            'what_the_business_says_about_itself' => $this->businessFacts(),
            'already_planned_titles' => $planned,
            'current_proposal' => $plan->assistant_version > 0 ? [
                'summary' => $plan->assistant_summary,
                'strategy' => $plan->assistant_strategy,
                'ideas' => $plan->contentIdeas()
                    ->where('proposal_version', $plan->assistant_version)
                    ->get()
                    ->map(fn (ContentIdea $idea): array => $this->ideaContext($idea))
                    ->all(),
            ] : null,
            'operator_instruction' => $message,
        ];

        return (string) json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Read the site now if nobody has read it yet.
     *
     * The corpus is normally filled by research, which runs weekly and harvests
     * sixty pages at a time. That is the right home for it and the wrong thing
     * to depend on: {@see ProjectLaunch::begin()} dispatches
     * research and the first Studio proposal *at the same time*, so on a new
     * project the proposal races the harvest and generally wins — and a month
     * planned without facts is not re-planned when the facts arrive. Every
     * project migrated into this feature has the same empty corpus for the same
     * reason.
     *
     * So the proposal is made self-sufficient rather than ordered after
     * research. The contours stay independent, which is the property
     * `ProjectLaunch` is built around, and an operator proposing a month out of
     * band gets the same answer as one whose research happened to run first.
     *
     * Bounded well below the weekly harvest: this is inside a step with a
     * deadline, each page is an HTTP request, and twenty commercial pages is
     * already more than {@see businessFacts()} will pass on. Only when there is
     * nothing at all — a corpus with one page in it is research's business to
     * grow, not this method's.
     */
    private function ensureFacts(Project $project, ModelSession $models): void
    {
        if (SitePage::query()->commercial()->exists()) {
            return;
        }

        $this->library->harvest($project, $models, self::FACTS_ON_DEMAND);
    }

    /**
     * The commercial pages, as text the planner may quote from.
     *
     * Bounded twice. Twelve pages because a small business states its whole
     * offer in fewer and a large one repeats itself, and 1,500 characters
     * because a services page says what it sells in its first screen and
     * spends the rest reassuring. Together that is the part of the prompt
     * carrying facts, and it is smaller than the list of article titles it
     * sits beside.
     *
     * Empty is a real answer and is left empty rather than padded: a site with
     * no commercial pages gives the planner nothing to be specific about, and
     * the honest consequence is a vaguer month rather than an invented one.
     *
     * @return list<array{url: string, title: string, says: string}>
     */
    private function businessFacts(): array
    {
        $facts = [];

        foreach (SitePage::query()->commercial()->limit(12)->get() as $page) {
            $facts[] = [
                'url' => $page->url,
                'title' => $page->title,
                'says' => Str::limit((string) $page->body, 1500),
            ];
        }

        return $facts;
    }

    /**
     * @return array{summary: string, strategy: array<string, mixed>, ideas: list<array<string, mixed>>}
     */
    private function normaliseProposal(string $text, Carbon $month): array
    {
        $decoded = $this->decodeObject($text);

        if ($decoded === null) {
            throw new InvalidAssistantResponse('The assistant did not return a structured monthly proposal.');
        }

        $summary = $this->text($decoded['summary'] ?? null, 5000);

        if ($summary === '') {
            throw new InvalidAssistantResponse('The assistant proposal has no summary.');
        }

        $facts = [];

        foreach ($this->list($decoded['site_facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $claim = $this->text($fact['claim'] ?? null, 1000);

            if ($claim !== '') {
                $facts[] = [
                    'claim' => $claim,
                    'source' => $this->text($fact['source'] ?? null, 100),
                ];
            }
        }

        $pillars = [];

        foreach ($this->list($decoded['pillars'] ?? []) as $pillar) {
            if (! is_array($pillar)) {
                continue;
            }

            $name = $this->text($pillar['name'] ?? null, 120);

            if ($name !== '') {
                $pillars[] = [
                    'name' => $name,
                    'purpose' => $this->text($pillar['purpose'] ?? null, 1000),
                ];
            }
        }

        $roles = is_array($decoded['channel_roles'] ?? null) ? $decoded['channel_roles'] : [];
        $channelRoles = [];

        foreach (self::CHANNELS as $channel) {
            $channelRoles[$channel] = $this->text($roles[$channel] ?? null, 1000);
        }

        $ideas = [];
        $keys = [];

        foreach (array_slice($this->list($decoded['ideas'] ?? []), 0, 40) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $date = $this->dateInMonth($raw['date'] ?? null, $month);
            $title = $this->text($raw['title'] ?? null, 255);
            $thesis = $this->text($raw['thesis'] ?? null, 5000);

            if ($date === null || $title === '' || $thesis === '') {
                continue;
            }

            $baseKey = Str::slug($this->text($raw['key'] ?? $title, 120));
            $baseKey = $baseKey === '' ? 'idea-'.($index + 1) : $baseKey;
            $key = $baseKey;
            $suffix = 2;

            while (isset($keys[$key])) {
                $key = $baseKey.'-'.$suffix++;
            }

            $keys[$key] = true;
            $kind = PostKind::tryFromLoose($raw['kind'] ?? null) ?? PostKind::fallback();

            $ideas[] = [
                'idea_key' => $key,
                'title' => $title,
                'kind' => $kind,
                'pillar' => $this->text($raw['pillar'] ?? null, 255),
                'thesis' => $thesis,
                'evidence' => $this->textList($raw['evidence'] ?? [], 20, 1000),
                'goal' => $this->text($raw['goal'] ?? null, 255),
                'audience' => $this->text($raw['audience'] ?? null, 500),
                'angle' => $this->text($raw['angle'] ?? null, 2000) ?: null,
                'channels' => $this->channelsFor($kind, $raw['channels'] ?? null),
                'scheduled_for' => $date,
            ];
        }

        if (count($ideas) < 4) {
            throw new InvalidAssistantResponse('The assistant returned fewer than four usable ideas for the month.');
        }

        usort(
            $ideas,
            static fn (array $left, array $right): int => strcmp($left['scheduled_for'], $right['scheduled_for']),
        );

        return [
            'summary' => $summary,
            'goal' => $this->normaliseGoal($decoded['goal'] ?? null),
            'strategy' => [
                'site_facts' => $facts,
                'assumptions' => $this->textList($decoded['assumptions'] ?? [], 20, 1000),
                'objectives' => $this->textList($decoded['objectives'] ?? [], 20, 1000),
                'pillars' => $pillars,
                'channel_roles' => $channelRoles,
                'questions' => $this->textList($decoded['questions'] ?? [], 5, 1000),
                // The one sentence tying the cadence to the target. Kept on the
                // strategy rather than on `content_goals` because it describes
                // *this* proposal's argument for the number, and a goal outlives
                // every proposal made against it — see the goals migration.
                'expected_impact' => $this->text(
                    is_array($decoded['goal'] ?? null) ? ($decoded['goal']['expected_impact'] ?? null) : null,
                    1000,
                ),
            ],
            'ideas' => $ideas,
        ];
    }

    /**
     * What the month is aiming at, or null if the model did not say usably.
     *
     * **Null rather than a default.** A missing target is the one field here
     * with no safe fallback: any number invented to fill it is indistinguishable
     * on the screen from one the model reasoned about, and the operator approves
     * a month against it. The whole point of moving this off the operator's
     * blank form was to stop measuring a month against a number nobody chose, and
     * a silent default would reintroduce exactly that with the model's
     * authorship on it. A proposal with no goal renders as a proposal with no
     * goal, and the twenty ideas beside it are still worth having.
     *
     * @return array{kpi: SocialKpi, target: int, cadence: int, weeks: list<array{objective: string}>}|null
     */
    private function normaliseGoal(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $kpi = SocialKpi::tryFrom(strtolower(trim($this->text($raw['kpi'] ?? null, 50))));
        $target = filter_var($raw['target'] ?? null, FILTER_VALIDATE_INT);
        $cadence = filter_var($raw['cadence'] ?? null, FILTER_VALIDATE_INT);

        // Bounded by the same rules the operator's own form is validated
        // against, so a goal cannot arrive through the model that would have
        // been refused from a person.
        if ($kpi === null || $target === false || $cadence === false) {
            return null;
        }

        if ($target < 1 || $target > 100_000_000 || $cadence < 1 || $cadence > 50) {
            return null;
        }

        return [
            'kpi' => $kpi,
            'target' => $target,
            'cadence' => $cadence,
            'weeks' => array_map(
                static fn (string $objective): array => ['objective' => $objective],
                $this->textList($raw['weeks'] ?? [], ContentGoal::WEEKS, 500),
            ),
        ];
    }

    /**
     * One idea's drafts, as reviewable rows.
     *
     * The per-channel existence check stays inside the transaction: two runs
     * racing on the same idea must not both create the row, and the unique
     * constraint is not the only thing that would notice.
     *
     * @param  array<string, array{body: string, payload: array<string, mixed>}>  $drafts
     * @return list<string>
     */
    private function persist(
        ContentPlan $plan,
        ContentIdea $idea,
        array $drafts,
        ?BrandBrief $brief,
        string $operationId,
        ?string $signalId = null,
    ): array {
        return DB::transaction(function () use ($plan, $idea, $drafts, $brief, $operationId, $signalId): array {
            $created = [];

            foreach ($drafts as $channel => $draft) {
                $existing = ContentItem::query()
                    ->where('content_idea_id', $idea->getKey())
                    ->where('channel_type', $channel)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                $item = ContentItem::query()->create([
                    'content_plan_id' => $plan->getKey(),
                    'content_idea_id' => $idea->getKey(),
                    'brand_brief_id' => $brief?->getKey(),
                    'locale' => $plan->project->default_locale,
                    'type' => ContentItemType::SocialPost,
                    'slug' => $this->slugFor($idea, $channel),
                    'title' => Str::limit($idea->title.' — '.ChannelType::from($channel)->label(), 255, ''),
                    'entities' => [],
                    'scheduled_for' => $idea->scheduled_for,
                    'channel_type' => $channel,
                    // The reason this post exists, where there was one. A real
                    // column rather than a payload key, because §3's whole
                    // argument for signals being a table is that the loop
                    // learns by source — and `whereNotNull('signal_id')` is the
                    // question that answers.
                    'signal_id' => $signalId,
                ]);

                // The queued Studio pipeline still crosses the same
                // state-machine edges. No hidden door into Draft.
                $item->markQueued()->markGenerating();
                $item->forceFill([
                    'body_markdown' => $draft['body'],
                    'summary' => $idea->thesis,
                    'channel_payload' => [
                        ...$draft['payload'],
                        'pipeline_run_id' => $operationId,
                    ],
                ])->save();
                $item->markDrafted();
                $created[] = (string) $item->getKey();
            }

            return $created;
        });
    }

    /**
     * One idea, written for each of its channels separately.
     *
     * **Separately is the whole change.** This used to be one model call that
     * returned Threads, X and Instagram in a single JSON object, and a model
     * asked for three posts in one answer writes one post and paraphrases it
     * twice — the first shape it thought of, trimmed to each limit. That is
     * cross-posting produced on purpose, and {@see ChannelType::takesArticleDerivatives()}
     * already spends a docblock on why it fails: a post that reads as a slice
     * of something else does not merely score zero, it teaches the platform to
     * show the next one to fewer people.
     *
     * Three channels asked three times, each with its own rules and its own
     * pool, cost more tokens and produce three posts. §4.3 made that trade for
     * the Threads contour and stated the reason in one line — tokens are
     * cheaper than reach — and nothing about it is specific to Threads.
     *
     * @param  list<string>  $channels
     * @return array<string, array{body: string, payload: array<string, mixed>}>
     */
    private function draftIdea(
        ContentPlan $plan,
        ContentIdea $idea,
        array $channels,
        ModelSession $models,
    ): array {
        $brief = BrandBrief::activeFor($plan->project);
        $drafts = [];
        $siblings = [];
        $spent = [];
        // Seeded from the month, then grown as this idea's own channels are
        // written. Same argument as `$siblings` below, one level up: the
        // convergence there was two channels of one idea reaching the same
        // sentence, and this is five ideas of one month reaching the same
        // photograph.
        $shots = $this->photographed($plan);

        foreach ($channels as $channel) {
            $draft = $this->draftChannel(
                ChannelPlaybook::for(ChannelType::from($channel)),
                $plan,
                $idea,
                $models,
                $brief,
                $siblings,
                $spent,
                $shots,
            );

            $drafts[$channel] = $draft;
            $spent[] = (string) ($draft['payload']['angle'] ?? '');

            $subject = $draft['payload']['visual']['subject'] ?? null;

            if (is_string($subject) && trim($subject) !== '') {
                $shots[] = trim($subject);
            }

            // What the idea's other channels already said, carried into the
            // next one. Separate calls stopped the channels being trimmed
            // copies of one draft, but they did not stop two of them arriving
            // at the same sentence: given one thesis and one angle, a model
            // converges. A live run produced a Threads post and an X post
            // carrying the same question word for word. The only party that can
            // avoid it is the one writing second, and only if it is shown the
            // first.
            $siblings[ChannelType::from($channel)->label()] = $draft['body'];
        }

        return $drafts;
    }

    /**
     * What this month's drafts have already been photographed as.
     *
     * Read from the plan rather than carried through the call, because a month
     * is not drafted in one run: {@see ContentStudioAction::GenerateIdea} is
     * one run per idea, deliberately, so the only thing an idea shares with the
     * one drafted before it is the database.
     *
     * **Including this idea's own items**, which was not the first answer. The
     * argument for excluding them is that a redraft should be free to arrive
     * near where it was — but a redraft deletes the items first, so the
     * exclusion never fired there. Where it did fire is the partial case: an
     * idea whose Threads post exists and whose X post is being written now, and
     * there the sibling's photograph is precisely the one the new channel must
     * not repeat. It is the in-run `$shots` list continued across runs.
     *
     * Newest last, so the tail passed to the prompt is the recent history
     * rather than the oldest eight subjects of the month.
     *
     * @return list<string>
     */
    private function photographed(ContentPlan $plan): array
    {
        $payloads = ContentItem::query()
            ->whereHas('contentIdea', fn (Builder $query) => $query
                ->where('content_plan_id', $plan->getKey()))
            ->orderBy('created_at')
            ->pluck('channel_payload');

        $subjects = [];

        foreach ($payloads as $payload) {
            $subject = is_array($payload) && is_string($payload['visual']['subject'] ?? null)
                ? trim($payload['visual']['subject'])
                : '';

            if ($subject !== '' && ! in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
            }
        }

        return $subjects;
    }

    /**
     * Write a pool for one channel and keep one of it.
     *
     * The four steps are the contour's, in the contour's order: write several,
     * score them deterministically, refuse the ones that could not be published
     * as written, and check the survivor's facts if the project is one where a
     * wrong number matters. What differs is only what happens at the bottom —
     * see {@see chooseCandidate()}.
     *
     * @param  array<string, string>  $siblings  what this idea already says elsewhere, by channel
     * @param  list<string>  $spent  the shapes this idea's other channels already took
     * @param  list<string>  $shots  the photographs this month has already briefed
     * @return array{body: string, payload: array<string, mixed>}
     */
    private function draftChannel(
        ChannelPlaybook $playbook,
        ContentPlan $plan,
        ContentIdea $idea,
        ModelSession $models,
        ?BrandBrief $brief,
        array $siblings = [],
        array $spent = [],
        array $shots = [],
    ): array {
        $pool = array_map(
            fn (StudioCandidate $candidate): StudioCandidate => $candidate->judged(
                ChannelPostScore::score(
                    $playbook,
                    $candidate->segments,
                    $candidate->link,
                    $candidate->chainReason !== null,
                ),
                StudioPostGuard::check(
                    $playbook,
                    $candidate->segments,
                    $candidate->link,
                    $candidate->chainReason !== null,
                    $brief,
                    $candidate->panelText(),
                ),
            ),
            $this->writePool($playbook, $plan, $idea, $models, $brief, $siblings, $spent, $shots),
        );

        $ranked = $this->rank($pool);
        $scores = array_map(static fn (StudioCandidate $c): int => $c->score, $pool);
        $bar = $this->selectionFloor();

        [$chosen, $factCheck] = $this->factCheck($plan->project, $ranked, $models);

        return $this->assemble($playbook, $idea, $chosen, $scores, $bar, $factCheck);
    }

    /**
     * The candidates, in the order they were written.
     *
     * One call per candidate rather than one call for the pool, for the reason
     * {@see DraftCandidates} states: asking a
     * model for four posts in one response gets four paraphrases of whichever
     * it thought of first, and the point of a pool is that its members differ
     * enough to be worth choosing between. Asking four times, each time for a
     * different one of the channel's shapes, is what produces that.
     *
     * A candidate that comes back malformed is retried once with the reason,
     * then abandoned — the pool loses a member rather than the idea losing a
     * channel. Only a pool that ends up empty is a failure, and it raises the
     * last parser's complaint so the operator sees what the model kept doing.
     *
     * @param  array<string, string>  $siblings  what this idea already says elsewhere, by channel
     * @param  list<string>  $spent  the shapes this idea's other channels already took
     * @param  list<string>  $shots  the photographs this month has already briefed
     * @return list<StudioCandidate>
     */
    private function writePool(
        ChannelPlaybook $playbook,
        ContentPlan $plan,
        ContentIdea $idea,
        ModelSession $models,
        ?BrandBrief $brief,
        array $siblings = [],
        array $spent = [],
        array $shots = [],
    ): array {
        $angles = $this->angles($playbook, $idea, $spent);
        $pool = [];
        $lastError = null;

        for ($written = 0; $written < $this->poolSize(); $written++) {
            $angle = $angles[$written % count($angles)];
            $correction = null;

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $answer = $models->send(new ModelRequest(
                    role: 'draft',
                    instructions: $this->channelInstructions($playbook, $idea, $brief),
                    prompt: $this->channelPrompt($playbook, $idea, $angle, $correction, $siblings, $shots),
                ));

                try {
                    // The picture brief is enforced on the first attempt only.
                    // It is a correction, and the correction has been made by
                    // the time the second answer arrives: refusing that one too
                    // would spend the candidate to fix its photograph, and a
                    // post whose picture is weak is worth more than no post.
                    // An unillustrated draft is already survivable — see
                    // `illustrate()`, which swallows a provider that will not
                    // draw — so the picture may not be what loses the words.
                    $pool[] = $this->parseCandidate(
                        $answer->text,
                        $playbook,
                        $idea,
                        $angle,
                        enforceVisual: $attempt === 1,
                    );

                    continue 2;
                } catch (InvalidAssistantResponse $e) {
                    $correction = $e->getMessage();
                    $lastError = $e->getMessage();
                }
            }
        }

        if ($pool === []) {
            throw new InvalidAssistantResponse(
                $lastError ?? "The assistant wrote nothing usable for {$playbook->channel->value}.",
            );
        }

        return $pool;
    }

    /**
     * The shapes this pool tries: the channel's, narrowed to the kind's.
     *
     * The channel knows what works on the platform and the kind knows what this
     * post is, and only their intersection is worth spending four calls on. An
     * opinion post asked for in the `caption` shape comes back as a caption —
     * the shape wins over the kind every time, because it is the more concrete
     * instruction — so leaving the channel's full list in place would spend a
     * quarter of every pool writing the post the plan did not ask for.
     *
     * Falls back to the channel's own list when the two do not overlap, so a
     * new channel added without a matching row in {@see PostKind::anglesOn()}
     * produces a slightly blunter pool rather than none at all.
     *
     * **And narrowed once more by what this idea's other channels already
     * took.** Separate calls and a sibling's text in the prompt were both not
     * enough: a live run put the same question on Threads and on X, word for
     * word, and the cause was not the model. {@see ChannelPostScore} pays 18
     * points for a question mark, so wherever an idea has one natural question,
     * the question-shaped candidate wins every channel's pool — the ranker
     * driving two channels to one post. Removing the spent shape is the only
     * one of the three fixes that acts on that, because it changes what the
     * pool is allowed to contain rather than what the model is asked for.
     *
     * @param  list<string>  $spent
     * @return list<string>
     */
    private function angles(ChannelPlaybook $playbook, ContentIdea $idea, array $spent = []): array
    {
        $narrowed = array_values(array_intersect(
            $idea->kind->anglesOn($playbook->channel),
            $playbook->angles,
        ));

        if ($narrowed === []) {
            $narrowed = $playbook->angles;
        }

        $fresh = array_values(array_diff($narrowed, $spent));

        // Only if something is left. A kind with one shape on one channel keeps
        // it rather than falling through to a shape the kind does not take.
        return $fresh === [] ? $narrowed : $fresh;
    }

    /**
     * Who this brand is and what this channel is, which do not change per angle.
     *
     * The channel's rules are here rather than in the prompt because they are
     * the standing instruction: everything in {@see channelPrompt()} is about
     * this one post, and everything here would be identical for the next one.
     * Splitting them that way is also what lets the four calls in a pool share
     * a prefix, which is the difference between four full-price calls and four
     * calls against a warm cache.
     */
    private function channelInstructions(
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        ?BrandBrief $brief,
    ): string {
        return implode("\n\n", array_filter([
            "You write posts for this brand on {$playbook->channel->label()}. You are writing one post, "
                .'native to this channel — not a version of something written for another one.',
            'Never invent a fact, a figure, a price or a date. Where there is no evidence for something, '
                .'write the opinion, the question or the framing instead of manufacturing the proof.',
            'Do not write like a press release, a newsletter or a company blog.',
            $playbook->rules(),
            // What this post *is*, which the channel's rules do not say and the
            // subject does not imply. Without it every idea came back written
            // as a how-to whatever it had been planned as, because "useful" is
            // the safest register a model can pick.
            $idea->kind->brief(),
            $brief?->compileToPrompt(),
            "The idea this post comes from: {$idea->title}\nThe thesis it has to keep: {$idea->thesis}",
            // The title above is working notes. It is the *idea's* title, it is
            // never published — a reader on any of these channels sees the post
            // and nothing else — and posts kept arriving written as its second
            // half: "Routine care holds the baseline; a deep clean goes beyond
            // usual cleaning" is a sentence that only parses under a headline
            // asking when routine stops being enough. A post that needs the
            // note to make sense is a post that reaches the reader broken.
            'That title and thesis are working notes, not part of the post. The reader never sees them. '
                .'Do not answer the title, continue from it, or assume it set anything up — whatever the '
                .'post needs the reader to know, the post itself has to say. Read your first sentence as '
                .'someone would meet it, with nothing above it.',
        ]));
    }

    /**
     * This post: the occasion, the evidence it may use, and one shape to take.
     *
     * The shape is the only thing that differs between the calls of a pool, and
     * it is prescribed rather than left open for the reason §2 gives about its
     * own list — a model asked to "write a good post" writes the average one,
     * and the average one is the shape none of these channels reward.
     *
     * @param  array<string, string>  $siblings  what this idea already says elsewhere, by channel
     * @param  list<string>  $shots  the photographs this month has already briefed
     */
    private function channelPrompt(
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        string $angle,
        ?string $correction,
        array $siblings = [],
        array $shots = [],
    ): string {
        $written = [];

        foreach ($siblings as $channel => $body) {
            $written[] = "{$channel}:\n".Str::limit($body, 500);
        }

        return implode("\n\n", array_filter([
            'Pillar: '.$idea->pillar,
            'Goal: '.$idea->goal,
            'Audience: '.$idea->audience,
            $idea->angle === null || $idea->angle === '' ? null : 'Editorial angle: '.$idea->angle,
            'The only evidence you may state: '.json_encode($idea->evidence, JSON_UNESCAPED_UNICODE),
            $playbook->shape($angle),
            $written === []
                ? null
                : 'This idea has already been written for another channel. Do not reuse its opening, its '
                    ."question or its phrasing — say something the reader of both would not read twice:\n"
                    .implode("\n\n", $written),
            // The same rule for the picture, at the month's scale. Asked only
            // to show work in contact with a surface, the writer found the one
            // shot that always satisfies it: eight of fourteen regenerated
            // briefs were a gloved hand with a detailing brush in a groove,
            // across five unrelated ideas. A feed of one photograph is its own
            // failure. Stated as what exists rather than as a list of banned
            // tools, because a ban pulls against "show the work" and the brief
            // that avoids a word usually avoids the work with it.
            $shots === []
                ? null
                : "Photographs already briefed for this month, most recent first:\n- "
                    .implode("\n- ", array_slice($shots, -8))
                    ."\nBrief a different photograph. Not a different wording of one of these — a "
                    .'different tool, surface, room or moment of the work, such that somebody scrolling '
                    .'the month would not think they had seen it already.',
            $correction === null ? null : "Your previous answer was invalid: {$correction}. Correct it.",
            $this->outputContract($playbook, $idea),
        ]));
    }

    /**
     * The JSON to answer with, including the six fields the picture needs.
     *
     * The art direction is asked for here, from the model that is writing the
     * post, because it is the only party that knows what the post is about.
     * {@see SocialImagePrompt} explains at length why one sentence of visual
     * brief produced the stock illustrations this release is fixing, and why
     * six named fields do not.
     */
    private function outputContract(ChannelPlaybook $playbook, ContentIdea $idea): string
    {
        $visual = '"visual":{"subject":"...","composition":"...","action":"...","location":"...",'
            .'"style":"...","light":"..."}';

        $shape = $playbook->isCaptionChannel()
            ? ($idea->instagramFormat() === 'carousel'
                ? '{"caption":"...","slides":[{"layout":"cover|statement|step|stat|contrast|checklist|cta",'
                    .'"heading":"...","body":"...","kicker":"...","figure":"...","before":"...","after":"...",'
                    .'"beforeLabel":"...","afterLabel":"...","items":["..."],"action":"..."}],'.$visual.'}'
                : '{"caption":"...",'.$visual.'}')
            : '{"segments":["the post"],"link":null,"chain_reason":null,'.$visual.'}';

        $limits = $playbook->isCaptionChannel()
            ? "The caption is at most {$playbook->segmentLimit} characters."
            : "Each segment is at most {$playbook->segmentLimit} characters. One segment. Only write a "
                .'second if the thought genuinely does not fit in one, and then say why in chain_reason — '
                .'a chain is an exception you have to justify.';

        return implode(' ', array_filter([
            $limits,
            $playbook->isCaptionChannel() && $idea->instagramFormat() === 'carousel'
                ? $this->carouselContract()
                : null,
            'The visual fields describe one photograph to go with this post: what is in it, how it is '
                .'framed, what is happening, where, in what style, and in what light. Be specific — a '
                .'vague brief produces a stock picture. Describe something small and near rather than a '
                .'whole room: hands, a tool, one surface, one object being used. Do not use the words '
                .'premium, elegant, editorial, luxury, pristine, sleek or minimalist — they produce a '
                .'showroom.',
            // The requirement the picture is judged against, given to the party
            // that writes the brief. It used to reach the image provider only,
            // appended after six fields written without knowledge of it, and a
            // provider handed a subject and a contradicting requirement draws
            // the subject: a `proof` post whose shot has to show a boundary
            // between cleaned and uncleaned came back as a hand holding a cloth
            // near a door, because that is what its own brief had asked for.
            'What this picture has to show: '.$idea->kind->shot().' Write the six fields so they deliver '
                .'that. If the thought behind this post has nothing photographable in it, do not reach '
                .'for an object that stands in for the idea — find the moment of real work that the '
                .'thought is about, and brief that.',
            // Not "no text" — no text-carriers. Told to keep words out of the
            // frame, the model kept asking for the prop and disclaiming its
            // content: "a checklist-style clipboard with no legible writing",
            // "a tablet showing an unlabeled checklist interface". Both drew
            // exactly what was asked for, and an empty form photographs as
            // something nobody filled in.
            'Never ask for text, words, numbers or logos in the image, and never ask for an object whose '
                .'whole purpose is to carry them: no clipboards, checklists, forms, notebooks, paperwork, '
                .'labels facing the camera, signage, phones, tablets or screens. A picture cannot show a '
                .'standard by photographing a list of it.',
            'Reply with JSON and nothing else: '.$shape,
        ]));
    }

    /**
     * How a carousel is built, said to the model that writes it.
     *
     * This used to be one sentence — "at most 10 slides, each with a short
     * heading and a concise body" — and it produced exactly what it asked for:
     * N interchangeable steps with no opening, no ending and nothing a reader
     * could not have got from the caption. Every slide then drew identically,
     * because the template had one shape, and the two failures compounded into
     * a format that looked like a list of paragraphs on coloured cards.
     *
     * The three rules that matter are stated as rules rather than as advice.
     * The hook decides whether anything after it is read at all; the count is
     * where saves and completion actually peak; and a carousel that ends without
     * asking for anything has spent the attention it earned and banked nothing.
     *
     * The figure rule is a refusal, not a preference. See
     * {@see SlideLayout::needsEvidence()} — a number at display size is the most
     * believable thing this engine can draw and therefore the most damaging
     * thing to invent.
     */
    private function carouselContract(): string
    {
        $layouts = implode(' ', [
            'Choose a layout for each slide, and choose it because the thought is that shape:',
            '- cover: the opening hook. Slide one is always this.',
            '- statement: one sentence that stands alone. Needs only a heading.',
            '- On a cover or a statement you may also give `highlight`: two or three words copied '
                .'exactly from that slide\'s heading, which are drawn in the brand\'s accent colour. '
                .'Choose the words the sentence turns on, not its subject — in "the one thing your '
                .'routine never reaches" that is "never reaches". Leave it out rather than colouring '
                .'half the line; a highlight longer than a few words is just a second colour of text.',
            '- step: one move in a sequence. Heading and body. The numbering is added for you.',
            '- stat: one figure, shown at the size of the whole slide. Give `figure` short — '
                .'"68%", "3x", "4.9" — and the heading is the line read under it.',
            '- contrast: two halves. `before` is what people assume, `after` is what is true.',
            '- checklist: three to five short `items` under a heading.',
            '- cta: the closing ask. The final slide is always this.',
        ]);

        return implode(' ', [
            'Between 5 and 8 slides — that is where a carousel is actually read to the end.',

            'Slide one is a cover whose heading opens a gap the rest of the carousel closes. '
                .'"5 cleaning tips" closes it immediately and nobody swipes; "the one thing your routine '
                .'never reaches" opens it. Do not summarise the post on the cover.',

            'The last slide is a cta asking for exactly one thing — save it, follow, send a message. One, '
                .'not three.',

            $layouts,

            'Vary the layouts. Four steps in a row is the format this replaces; if the middle of the '
                .'carousel is all one shape, the argument is probably a list and would read better as one.',

            // "Vary" on its own does not bind. A real answer came back as
            // cover, contrast, step, step, checklist, checklist, cta — and only
            // one of those repeats is a fault, which is why the rule names the
            // exception rather than forbidding repetition outright.
            // Written first as "do not repeat a layout, except step" — and the
            // exception swallowed the rule: the next carousel came back as a
            // cover and five consecutive steps, which is precisely the format
            // the line above says this replaces. An exemption a model can lean
            // on is an instruction to lean on it.
            'Concretely: do not use the same layout on two slides in a row, and never on three. Steps '
                .'may pair — two in a row read as a sequence because the numbers differ — but a third '
                .'consecutive step is a slideshow. Two checklists in a row are the same slide twice. '
                .'A how-to is not obliged to be steps end to end: the strongest middle is usually a '
                .'contrast for the mistake, steps for the method, and a checklist for what to take away.',

            // The whole guard, said plainly. The parser enforces it too — see
            // ContentStudioAssistant::sourcedFigure() — but a model told the
            // rule writes a better slide than one whose slide is silently
            // rewritten into a statement afterwards.
            'Use a stat slide only for a figure that appears in this idea\'s evidence. Do not estimate, '
                .'round up, or invent a number to fill the layout — a figure you cannot source will be '
                .'redrawn as a plain statement.',

            'Every slide has a heading whatever its layout: it is the picture\'s alt text and its line in '
                .'the caption, so it has to say the slide\'s point on its own.',
        ]);
    }

    /**
     * One model answer as one candidate.
     *
     * Strict about the two things a later step cannot recover from — the length
     * the platform enforces and the presence of any text at all — because those
     * are what the retry in {@see writePool()} exists to correct, and tolerant
     * about everything else. A malformed `visual` object costs the candidate
     * its art direction and {@see SocialImagePrompt} its defaults, not the
     * candidate itself.
     *
     * `$enforceVisual` adds a third strict thing, and it is the only
     * conditional one — see the call site in {@see writePool()} for why it is
     * asked of a first answer and forgiven on a second.
     */
    private function parseCandidate(
        string $text,
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        string $angle,
        bool $enforceVisual = true,
    ): StudioCandidate {
        $decoded = $this->decodeObject($text);

        if ($decoded === null) {
            throw new InvalidAssistantResponse('Draft output was not a JSON object.');
        }

        $visual = is_array($decoded['visual'] ?? null) ? $decoded['visual'] : [];
        $channel = $playbook->channel->value;

        // Raised as an invalid answer rather than scored down, because the
        // retry that follows is the whole remedy: the writer is the only party
        // that knows what the post is about, and told what is wrong with its
        // brief it writes a better one.
        $complaints = $enforceVisual ? VisualBriefGuard::check($visual, $idea->kind) : [];

        if ($complaints !== []) {
            throw new InvalidAssistantResponse(implode(' ', $complaints));
        }

        if ($playbook->isCaptionChannel()) {
            return $this->parseCaption($decoded, $playbook, $idea, $angle, $visual);
        }

        $segments = $this->draftSegments(
            $decoded['segments'] ?? [],
            $playbook->maxSegments,
            $playbook->segmentLimit,
            $channel,
        );

        if ($segments === []) {
            throw new InvalidAssistantResponse("{$channel} has no usable text.");
        }

        $link = $this->text($decoded['link'] ?? null, 2000);
        $reason = $this->text($decoded['chain_reason'] ?? null, 1000);

        return new StudioCandidate(
            angle: $angle,
            format: count($segments) > 1 ? 'thread' : 'post',
            segments: $segments,
            link: $link === '' ? PostFormat::firstUrl(implode("\n", $segments)) : $link,
            // Only kept where it means something: a justification attached to a
            // single post would be carried into the guard as though a chain had
            // been argued for.
            chainReason: count($segments) > 1 && $reason !== '' ? $reason : null,
            visual: $visual,
        );
    }

    /**
     * Instagram, where the post is a caption and possibly a set of panels.
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $visual
     */
    private function parseCaption(
        array $decoded,
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        string $angle,
        array $visual,
    ): StudioCandidate {
        $caption = $this->untruncatedText(
            $decoded['caption'] ?? null,
            $playbook->segmentLimit,
            'Instagram caption',
        );

        if ($caption === '') {
            throw new InvalidAssistantResponse('Instagram has no caption.');
        }

        if ($idea->instagramFormat() === 'image') {
            return new StudioCandidate(
                angle: $angle,
                format: 'image',
                segments: [$caption],
                visual: $visual,
            );
        }

        $slides = $this->parseSlides($this->list($decoded['slides'] ?? []), $idea);

        if ($slides === []) {
            throw new InvalidAssistantResponse('Instagram carousel has no usable slides.');
        }

        return new StudioCandidate(
            angle: $angle,
            format: 'carousel',
            segments: [$caption],
            slides: $slides,
            visual: $visual,
        );
    }

    /**
     * The model's slides, normalised into layouts this engine can draw.
     *
     * **Degrades, never refuses.** Every failure here is a slide asking to be
     * something it did not supply the fields for, and the sentence on it is
     * still worth publishing — so a `contrast` with one half becomes a
     * statement rather than a hole in the argument. The only thing dropped is a
     * slide with no heading, because a heading is what the caption and the alt
     * text are made of.
     *
     * Position decides what a slide is allowed to be, so a model that opened
     * with a checklist or ended mid-sequence still produces a carousel with a
     * cover and an ending. See {@see SlideLayout::allowedAt()}.
     *
     * @param  list<mixed>  $raw
     * @return list<array{heading: string, body: string, layout: string, fields: array<string, mixed>}>
     */
    private function parseSlides(array $raw, ContentIdea $idea): array
    {
        // The upper bound is the format's, not the model's. Eight is where
        // completion stops paying; ten was an arbitrary ceiling that let a
        // rambling answer through intact.
        // Filtered before anything is positioned, because position is a promise
        // about the *surviving* slides. Counting the raw answer meant that a
        // model omitting the last slide's heading — plausible for a `cta`, whose
        // real payload is its `action` — left no slide at the final position at
        // all, so the carousel ended on a middle layout with nothing asked for.
        // The docblock above promises the opposite.
        $usable = [];

        foreach (array_slice($raw, 0, 8) as $slide) {
            if (! is_array($slide)) {
                continue;
            }

            $heading = $this->untruncatedText($slide['heading'] ?? null, 120, 'Instagram slide heading');

            if ($heading !== '') {
                $usable[] = ['heading' => $heading, 'raw' => $slide];
            }
        }

        $count = count($usable);
        $slides = [];
        $step = 0;

        // The layout the slide before settled on, so this one can refuse to be
        // the same shape twice running. Carried rather than read back off
        // `$slides` because what matters is the settled layout, and a slide
        // that fell back is still what the reader sees.
        $previous = null;
        $run = 0;

        foreach ($usable as $index => ['heading' => $heading, 'raw' => $slide]) {
            $layout = $this->slideLayout($slide, $index + 1, $count, $previous, $run);
            $fields = [];

            foreach ($layout->fields() as $field => $limit) {
                // Truncated, not refused. `untruncatedText` throws, and these
                // are short optional fields with tight limits that the model is
                // never told — so a 62-character kicker discarded the entire
                // candidate and burned a retry, and a batch where every
                // candidate overran failed the channel outright. That is the
                // opposite of what this method promises. The heading above
                // still throws, because a heading over 120 characters is a
                // model ignoring the shape rather than overrunning a label.
                $value = $this->text($slide[$field] ?? null, $limit);

                if ($value !== '') {
                    $fields[$field] = $value;
                }
            }

            // A highlight the heading does not contain cannot be drawn: the
            // renderer colours a run *of* the heading rather than printing a
            // second string, so a paraphrase — the ordinary way a model gets
            // this wrong — would silently colour nothing. Dropped rather than
            // repaired, because the repair would be guessing which words were
            // meant.
            if (isset($fields['highlight'])
                && mb_stripos($heading, $fields['highlight']) === false) {
                unset($fields['highlight']);
            }

            // The one list-valued field, so it is read here rather than being
            // given a character limit in fields() that would mean nothing.
            // Five, because a sixth tick slides off the frame.
            if ($layout === SlideLayout::Checklist) {
                $items = $this->textList($slide['items'] ?? [], 5, 120);

                if ($items !== []) {
                    $fields['items'] = $items;
                }
            }

            $layout = $this->settleLayout($layout, $fields, $idea);

            // Counted on the settled layout, not the requested one: a slide
            // that fell back to statement is a statement on the screen, and
            // the next slide has to know that.
            $run = $layout === $previous ? $run + 1 : 1;
            $previous = $layout;

            if ($layout === SlideLayout::Step) {
                $fields['step'] = (string) ++$step;
            }

            $slides[] = [
                'layout' => $layout->value,
                'heading' => $heading,
                // Read from the slide rather than out of `fields`, and the two
                // are different jobs rather than a duplication: this is the
                // slide's prose for the caption and the post body, which every
                // layout has whether or not its template draws a paragraph.
                // `fields['body']` is what `step` and `cta` put on the picture.
                'body' => $this->text($slide['body'] ?? null, 500),
                'fields' => $fields,
            ];
        }

        return $slides;
    }

    /**
     * What the model asked for, if it is allowed here.
     *
     * An unknown or absent layout is not an error: the field is new, and an
     * answer written against the older contract is still a usable slide. It
     * lands on whatever this position permits.
     *
     * @param  array<string, mixed>  $slide
     */
    private function slideLayout(
        array $slide,
        int $position,
        int $count,
        ?SlideLayout $previous = null,
        int $run = 0,
    ): SlideLayout {
        $allowed = SlideLayout::allowedAt($position, $count);
        $asked = SlideLayout::tryFrom(strtolower(trim((string) ($slide['layout'] ?? ''))));

        // A run of one shape, capped. The prompt asks for this and asking is
        // most of the work, but it is not all of it: told "vary the layouts" a
        // model returned cover, contrast, step, step, checklist, checklist; told
        // additionally that step was exempt from repeating, the next one
        // returned a cover and five consecutive steps.
        //
        // Two for most layouts, because consecutive steps carry different
        // numbers and read as a sequence rather than as the same slide twice.
        // One for statement, which is also what every unrecognised layout falls
        // to — so a model naming two layouts this engine does not have produces
        // two identical slides without having asked for either.
        $cap = $previous === SlideLayout::Statement ? 1 : 2;

        if ($previous !== null && $run >= $cap) {
            // Out of the fallback too, not merely out of the ask. Statement is
            // what `$allowed[0]` *is* in the middle, so refusing the request and
            // then falling back would land on it again — the repeat this exists
            // to stop, arrived at by a different route.
            $allowed = array_values(array_filter(
                $allowed,
                static fn (SlideLayout $layout): bool => $layout !== $previous,
            ));

            if ($asked === $previous) {
                $asked = null;
            }
        }

        if ($asked !== null && in_array($asked, $allowed, true)) {
            return $asked;
        }

        // The first permitted layout, which for the two fixed positions is the
        // only one. In the middle it is Statement — the layout that needs
        // nothing but the heading every slide already has.
        //
        // Empty only if a fixed position offered nothing but statement, which
        // no position does; the guard is here because a filtered list that
        // turned out empty would otherwise be an undefined index rather than a
        // slide.
        return $allowed[0] ?? SlideLayout::Statement;
    }

    /**
     * Whether the slide may keep the layout it asked for.
     *
     * Two ways it may not. A layout missing a field it cannot be drawn without
     * falls back; and a figure the idea cannot source is refused outright,
     * because a number at display size is the most believable thing this engine
     * draws and therefore the most damaging thing to invent.
     *
     * @param  array<string, mixed>  $fields
     */
    private function settleLayout(SlideLayout $layout, array $fields, ContentIdea $idea): SlideLayout
    {
        foreach ($layout->required() as $field) {
            if (! isset($fields[$field])) {
                return $layout->fallback();
            }
        }

        if ($layout->needsEvidence() && ! $this->sourcedFigure((string) ($fields['figure'] ?? ''), $idea)) {
            return $layout->fallback();
        }

        return $layout;
    }

    /**
     * Whether a figure appears in what this idea was planned from.
     *
     * Digits rather than the whole string, and deliberately loose. The slide
     * says "68%" where the evidence says "68 per cent of clients rebook"; a
     * literal match would reject the honest case and teach nobody anything.
     * What it does catch is the invented one — a model reaching for "3x faster"
     * with nothing behind it writes digits that appear nowhere in its own
     * source material.
     *
     * A figure with no digits at all — "most", "nearly all" — is not a figure
     * and does not belong at 300px, so it fails too.
     *
     * Evidence that spells its number as a word does not source a numeral, and
     * that is deliberate rather than an oversight: this engine writes in four
     * languages, so reading "four" means also reading "quatro", "четыре" and
     * "чотири", and a matcher that is only right in English would wave figures
     * through in the other three. The failure it produces is the safe one — a
     * true figure demoted to a statement — and the fix is to write the evidence
     * with a numeral.
     */
    private function sourcedFigure(string $figure, ContentIdea $idea): bool
    {
        $wanted = $this->numbersIn($figure);

        if ($wanted === []) {
            return false;
        }

        $known = $this->numbersIn(implode(' ', [
            implode(' ', $idea->evidence),
            $idea->thesis,
            $idea->title,
        ]));

        return array_intersect($wanted, $known) !== [];
    }

    /**
     * Every number in a string, as comparable digit-runs.
     *
     * **Numbers are found first and flattened second**, which is the whole
     * point. Stripping non-digits from the string up front shatters "4.9" into
     * "4" and "9", so a slide saying 4.9 could never match evidence saying 4.9 —
     * and "4.9" is one of the three examples the prompt gives of a good figure.
     * Every decimal and every thousands-grouped number was silently demoted to a
     * statement, including the ones stated verbatim in the idea's own evidence.
     *
     * Separators are dropped rather than interpreted. "4.9" and "4,9" are the
     * same figure in the four languages this engine writes, and deciding whether
     * a comma groups thousands or marks a decimal needs a locale nobody has
     * passed down here — while "49" matching "49" is right in all of them.
     *
     * @return list<string>
     */
    private function numbersIn(string $text): array
    {
        preg_match_all('/\d[\d.,\s]*\d|\d/u', $text, $matches);

        $numbers = array_map(
            static fn (string $number): string => (string) preg_replace('/\D+/', '', $number),
            $matches[0],
        );

        return array_values(array_unique(array_filter(
            $numbers,
            static fn (string $number): bool => $number !== '',
        )));
    }

    /**
     * Best first: publishable before high-scoring, and score inside each group.
     *
     * The two-level sort is the point. A guard finding says the post could not
     * be published as written — over the platform's limit, on a topic the Brief
     * forbids, a bare link — and no score redeems that, so a clean candidate
     * scoring 52 is preferred to a refused one scoring 90. Inside each group
     * the score decides, and PHP's sort is stable, so a tie keeps the order the
     * angles were tried in: the channel's first-listed shape wins, which is the
     * one its playbook puts first on purpose.
     *
     * @param  list<StudioCandidate>  $pool
     * @return list<StudioCandidate>
     */
    private function rank(array $pool): array
    {
        $ranked = array_values(array_filter(
            $pool,
            static fn (StudioCandidate $candidate): bool => ! $candidate->isEmpty(),
        ));

        usort(
            $ranked,
            static fn (StudioCandidate $left, StudioCandidate $right): int => [$right->isClean(), $right->score]
                <=> [$left->isClean(), $left->score],
        );

        return $ranked;
    }

    /**
     * §10's fact check, on the survivor rather than on the pool.
     *
     * > **YMYL.** Для cryptoroute фактчек в `social_draft` — обязательный шаг,
     * > как в генерации. Неверное число в посте расходится быстрее, чем в
     * > статье.
     *
     * The Studio ran no check at all, on any project, which is the one gap in
     * this file that was a correctness problem rather than a quality one: an
     * operator reviewing a draft cannot see that a figure in it was invented.
     *
     * At most {@see FACT_CHECKS} candidates are checked, and a failure moves
     * down the ranking rather than failing the channel. Checking the whole pool
     * would be four expensive calls to protect a reader from three posts nobody
     * will ever see; checking only the first would mean one unlucky invented
     * number costs the idea a channel.
     *
     * On every other project it does not run. §10 names one, and a check nobody
     * needs is money spent on latency.
     *
     * @param  list<StudioCandidate>  $ranked
     * @return array{StudioCandidate, array{passed: bool, findings: list<string>}|null}
     */
    private function factCheck(Project $project, array $ranked, ModelSession $models): array
    {
        $best = $ranked[0] ?? throw new InvalidAssistantResponse(
            'Every candidate the assistant wrote for this channel was empty.',
        );

        if (! $project->is_ymyl) {
            return [$best, null];
        }

        // Only within the best candidate's own group. `$ranked` puts the
        // publishable candidates first, and walking past them would let a
        // fact-check pass promote a candidate the guard refused — a bare link
        // states no figures, so it passes every check, and it would arrive as
        // the draft with `passed: true` against it. The fact check chooses
        // between equals; it does not overturn {@see rank()}.
        $eligible = array_values(array_filter(
            $ranked,
            static fn (StudioCandidate $candidate): bool => $candidate->isClean() === $best->isClean(),
        ));

        $verdicts = [];

        foreach (array_slice($eligible, 0, self::FACT_CHECKS) as $candidate) {
            $findings = $this->factCheckFindings($project, $candidate, $models);

            if ($findings === []) {
                return [$candidate, ['passed' => true, 'findings' => []]];
            }

            $verdicts[] = $findings;
        }

        // Nothing passed. The best candidate still becomes the draft — this is
        // a review surface, and an operator shown a post with "this number is
        // not in your facts" against it can fix it, where an operator shown an
        // empty slot can only re-run and hope. What must not happen is the
        // draft arriving as though it had been checked.
        // The best candidate's own findings, not the union: the operator is
        // reading one post, and the runner-up's problems are not that post's.
        return [$best, ['passed' => false, 'findings' => $verdicts[0]]];
    }

    /**
     * The claims in one candidate that the project's own facts do not support.
     *
     * Shaped like {@see FactCheckPost}, down to
     * the PASS convention, because an article and a post disagreeing about what
     * counts as unsupported would be worse than either verdict alone.
     *
     * @return list<string>
     */
    private function factCheckFindings(
        Project $project,
        StudioCandidate $candidate,
        ModelSession $models,
    ): array {
        $facts = $project->original_data;

        $answer = $models->send(new ModelRequest(
            role: 'factcheck',
            instructions: 'You are a fact-checker. You are looking for claims that cannot be supported.',
            prompt: implode("\n\n", [
                $facts === []
                    ? 'No business facts were supplied, so any specific price, figure or date in the post '
                        .'is unsupported.'
                    : "The only facts about this business that are true:\n".json_encode($facts),
                // The panels too. A carousel's figures live on the slides far
                // more often than in its caption, and §10's argument is about
                // the figure rather than about where it sat.
                "The post:\n".$candidate->reviewText(),
                'List every claim that is unsupported or contradicts the facts above, one per line. '
                    .'If there are none, reply with exactly: PASS',
            ]),
        ));

        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', trim($answer->text)) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        // The whole answer must be PASS rather than merely contain it. A
        // finding reading "this claim does not pass" was once read as a clean
        // bill of health, and on a YMYL project that is the difference between
        // holding a post back and telling somebody the wrong number.
        if ($lines === [] || (count($lines) === 1 && mb_strtoupper($lines[0]) === 'PASS')) {
            return [];
        }

        $findings = [];

        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^\s*[-*\d.)]+\s*/u', '', $line) ?? '');

            if ($clean !== '') {
                $findings[] = $clean;
            }
        }

        return $findings;
    }

    /**
     * The chosen candidate as the row and the payload the Studio stores.
     *
     * Everything the selection knew is written down beside the post: which
     * shape won, what it scored, what the others scored, what the bar was, and
     * what is wrong with it if anything is. §7's rule that a silent machine is
     * indistinguishable from a broken one applies to a review surface most of
     * all — "the best of four, and it cleared the bar" and "the only one there
     * was" are different facts about the same draft, and until now the Studio
     * showed neither.
     *
     * @param  list<int>  $scores
     * @param  array{passed: bool, findings: list<string>}|null  $factCheck
     * @return array{body: string, payload: array<string, mixed>}
     */
    private function assemble(
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        StudioCandidate $chosen,
        array $scores,
        int $bar,
        ?array $factCheck,
    ): array {
        $payload = [
            'source' => 'content_assistant',
            'content_idea_id' => $idea->getKey(),
            'format' => $chosen->format,
            'angle' => $chosen->angle,
            'visual' => $chosen->visual,
            'selection' => $chosen->selectionReport($playbook, $scores, $bar),
            'guard_findings' => array_map(
                static fn (GuardFinding $finding): array => $finding->toArray(),
                $chosen->findings,
            ),
        ];

        if ($factCheck !== null) {
            $payload['fact_check'] = $factCheck;
        }

        // The name {@see \App\Support\Social\ChannelPayload} reads, which is
        // also the only one a publisher looks for. The link was being parsed,
        // scored against ({@see ChannelPostScore} docks an unframed one) and
        // guarded — and then dropped on the way to storage, so a candidate paid
        // for carrying a link and shipped without it.
        if ($chosen->link !== null && $chosen->link !== '') {
            $payload['link_attachment'] = $chosen->link;
        }

        if (! $playbook->isCaptionChannel()) {
            return [
                'body' => implode("\n\n---\n\n", $chosen->segments),
                'payload' => [
                    ...$payload,
                    'segments' => array_map(
                        static fn (string $segment): array => ['text' => $segment],
                        $chosen->segments,
                    ),
                ],
            ];
        }

        $caption = $chosen->segments[0] ?? '';

        if ($chosen->slides === []) {
            return ['body' => $caption, 'payload' => [...$payload, 'caption' => $caption]];
        }

        $body = $caption."\n\n".implode("\n\n", array_map(
            static fn (array $slide, int $index): string => sprintf(
                '%d. %s%s',
                $index + 1,
                $slide['heading'],
                $slide['body'] === '' ? '' : "\n{$slide['body']}",
            ),
            $chosen->slides,
            array_keys($chosen->slides),
        ));

        return [
            'body' => $body,
            'payload' => [...$payload, 'caption' => $caption, 'slides' => $chosen->slides],
        ];
    }

    /**
     * How many candidates to write per channel, inside the config's own bounds.
     *
     * Clamped rather than trusted, for the reason config/content_studio.php
     * states: a deployment that edits this to one has not made the Studio
     * cheaper, it has turned the selection step back into the generator.
     */
    private function poolSize(): int
    {
        $wanted = (int) config('content_studio.draft.candidates', 4);
        $min = (int) config('content_studio.draft.min_candidates', 2);
        $max = (int) config('content_studio.draft.max_candidates', 8);

        return max($min, min($max, $wanted));
    }

    private function selectionFloor(): int
    {
        return (int) config('content_studio.draft.selection_floor', PostFormat::BASE);
    }

    /**
     * The picture each draft ships with, drawn for the channel it ships on.
     *
     * Two things changed here and both were producing the pictures an operator
     * called unusable. It used to hand the draft's *title* — the idea's title
     * with " — Threads" appended — to {@see HeroImage::for()}, whose
     * prompt opens "An editorial image for an article titled", so the image
     * model was asked for an illustration of an article that does not exist.
     * And it took whatever size config/media.php calls a hero, which is
     * 1200×630: an Open Graph card, letterboxed in every feed these posts
     * appear in and cropped to a strip on Instagram.
     *
     * Now the six art-direction fields the writing model returned become the
     * prompt ({@see SocialImagePrompt}) and the channel decides the crop
     * ({@see ChannelPlaybook}), so Instagram gets 1080×1350, Threads a square
     * and X a 16:9.
     *
     * The brief is passed in rather than looked up here: the caller already
     * holds it, and reading it off `$item->project` would be a lazy load, which
     * this application refuses on purpose.
     *
     * @param  list<string>  $itemIds
     */
    private function illustrateDrafts(array $itemIds, ?BrandBrief $brief, ?ModelSession $models = null): void
    {
        if ($itemIds === []) {
            return;
        }

        foreach (ContentItem::query()->whereKey($itemIds)->with('contentIdea')->get() as $item) {
            $payload = $item->channel_payload ?? [];
            $channel = ChannelType::tryFrom((string) $item->channel_type);

            if ($channel === null) {
                continue;
            }

            $playbook = ChannelPlaybook::for($channel);

            // Two capabilities, configured separately and failing separately.
            // The photograph came first only in the order they were built, and
            // nesting the panels inside it made them depend on it: a deployment
            // with a renderer and no image provider — which the renderer's own
            // docblock calls supported — drew no slides at all, and a provider
            // that refused one post took that post's slides with it. A
            // carousel's steps have nothing to do with whether a photograph of
            // a kitchen was available.
            // A text post buys nothing. The shape has always allowed it —
            // `visual: 'none'` — and nothing ever produced it, so every post
            // paid for a photograph whether the post wanted one or not. An
            // opinion or a question is frequently stronger without one, and
            // this is the only choice on the idea that makes a post cheaper.
            if ($item->contentIdea?->format() === ContentFormat::Text) {
                continue;
            }

            $payload = $this->illustrate($item, $playbook, $payload, $brief, $models);

            $this->drawPanels($item, $playbook, $payload, $brief);
        }
    }

    /**
     * This draft's photograph, if there is a provider willing to draw one.
     *
     * Returns the payload it stored, so the caller reads what is on the row
     * rather than what it hoped would be.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function illustrate(
        ContentItem $item,
        ChannelPlaybook $playbook,
        array $payload,
        ?BrandBrief $brief,
        ?ModelSession $models,
    ): array {
        if (! $this->images->isConfigured()) {
            return $payload;
        }

        $prompt = is_array($payload['visual'] ?? null) && $payload['visual'] !== []
            ? SocialImagePrompt::fromFields($payload['visual'], $this->imageSubject($item))
            : SocialImagePrompt::fromBrief(null, $this->imageSubject($item));

        try {
            $made = $this->images->for($item, $playbook, $prompt, $brief, $item->contentIdea?->kind->shot());
        } catch (TerminalStepFailure) {
            // A provider that will not draw must not lose a post that is
            // already written and paid for. An unillustrated draft is a weaker
            // draft; a failed batch is no drafts at all.
            return $payload;
        }

        if ($made === null) {
            return $payload;
        }

        if (isset($payload['segments'][0]) && is_array($payload['segments'][0])) {
            $payload['segments'][0]['asset_id'] = (string) $made['asset']->getKey();
        }

        $payload['asset_id'] = (string) $made['asset']->getKey();
        $item->forceFill(['channel_payload' => $payload])->save();

        // Per picture rather than per token, and only when one was actually
        // bought — a hero already on the unit reports no provider. Without this
        // the Studio's images never reached §6's cost rows at all.
        $models?->spend($made['cost'], $made['provider'], $made['model']);

        return $payload;
    }

    /**
     * A carousel's slides, drawn as panels rather than left as caption text.
     *
     * The one thing a teaching post most needs to show is the step, and the one
     * thing a generated picture cannot contain is a legible word. So the slides
     * were written, concatenated into the body and shipped under a photograph
     * of a kitchen — the reader had to open the caption to find out what the
     * post was for.
     *
     * Skipped silently where there is no renderer or no slides. It is an
     * optional capability, and a deployment without it publishes exactly what
     * it published before.
     *
     * @param  array<string, mixed>  $payload
     */
    private function drawPanels(
        ContentItem $item,
        ChannelPlaybook $playbook,
        array $payload,
        ?BrandBrief $brief,
    ): void {
        $slides = $payload['slides'] ?? null;

        if (! is_array($slides) || $slides === []) {
            return;
        }

        $panels = [];

        foreach ($slides as $slide) {
            if (is_array($slide) && is_string($slide['heading'] ?? null)) {
                $panels[] = [
                    'heading' => (string) $slide['heading'],
                    'body' => (string) ($slide['body'] ?? ''),
                    // Absent on a carousel drafted before layouts existed, and
                    // resolved to the old flat template by CarouselPanels —
                    // those posts keep the pictures they were written for.
                    'layout' => is_string($slide['layout'] ?? null) ? $slide['layout'] : null,
                    'fields' => is_array($slide['fields'] ?? null) ? $slide['fields'] : [],
                ];
            }
        }

        app(CarouselPanels::class)->draw($item, $playbook, $panels, VisualStyle::fromBrief($brief));
    }

    /**
     * What the picture is of, when the model gave no art direction at all.
     *
     * The post itself rather than the title, which is the whole complaint about
     * the old prompt: the title is the idea plus the channel's name, and the
     * post is what the reader will see the picture next to.
     */
    private function imageSubject(ContentItem $item): string
    {
        $body = trim((string) $item->body_markdown);

        return $body === '' ? $item->title : Str::limit($body, 300);
    }

    /** @return Collection<int, ContentIdea> */
    private function ideasMissingDrafts(ContentPlan $plan): Collection
    {
        $ideas = $plan->contentIdeas()
            ->where('proposal_version', $plan->assistant_version)
            ->orderBy('scheduled_for')
            ->get();

        $draftsByKey = $this->draftsByIdeaKey($plan);

        foreach ($ideas as $idea) {
            $idea->setRelation(
                'contentItems',
                $draftsByKey->get($idea->idea_key, new Collection),
            );
        }

        return $ideas->filter(static function (ContentIdea $idea): bool {
            $drafted = $idea->contentItems->pluck('channel_type')->all();

            return array_diff($idea->channels, $drafted) !== [];
        })->values();
    }

    /** @return array<string, mixed> */
    private function ideaContext(ContentIdea $idea): array
    {
        return [
            'key' => $idea->idea_key,
            'date' => $idea->scheduled_for->toDateString(),
            'title' => $idea->title,
            // Without this the refine prompt showed the model an idea with
            // channels and no kind, and the instructions say the kind decides
            // the channels — so it re-derived one from an ambiguous pair
            // ([instagram, threads] is both proof and behind) and rewrote the
            // channels and the carousel/image format to match. That is the
            // silent format churn deleting `day % 2` removed, arriving again
            // one step later.
            'kind' => $idea->kind->value,
            'pillar' => $idea->pillar,
            'thesis' => $idea->thesis,
            'evidence' => $idea->evidence,
            'goal' => $idea->goal,
            'audience' => $idea->audience,
            'angle' => $idea->angle,
            'channels' => $idea->channels,
        ];
    }

    /**
     * Where this idea actually goes, which the kind decides and the model asks.
     *
     * Intersected rather than taken, exactly as the list is already intersected
     * against {@see CHANNELS}: the model proposes and the engine constrains.
     * A model that asks for all three gets the two its kind is native to, and
     * one that asks for none — or for a channel this deployment does not run —
     * gets the kind's own set rather than everything.
     *
     * The check that used to be here fell back to *all three channels* when the
     * model named none, which is how a plan where every idea went everywhere
     * survived: the fallback was the failure mode.
     *
     * @return list<string>
     */
    private function channelsFor(PostKind $kind, mixed $requested): array
    {
        $native = array_map(
            static fn (ChannelType $channel): string => $channel->value,
            $kind->channels(),
        );

        $asked = array_map('strval', $this->list($requested));
        $channels = array_values(array_intersect($native, $asked));

        return $channels === [] ? array_values(array_intersect(self::CHANNELS, $native)) : $channels;
    }

    private function slugFor(ContentIdea $idea, string $channel): string
    {
        return Str::limit(
            Str::slug(
                "{$idea->scheduled_for->format('Y-m')}-{$idea->idea_key}-{$channel}-v{$idea->proposal_version}",
            ),
            240,
            '',
        );
    }

    /**
     * Drafted ideas are historical facts, not model suggestions. A refinement
     * may reorganise the unwritten remainder of the month, but carries these
     * exact ideas into the new version. Their stable keys keep existing drafts
     * visible and prevent generating a second copy.
     *
     * @param  list<array<string, mixed>>  $proposed
     * @return list<array<string, mixed>>
     */
    private function preserveDraftedIdeas(ContentPlan $plan, array $proposed): array
    {
        // No early return at version 0, and that is the other half of the same
        // bug. A month with no proposal is not a month with no content: an
        // operator can write an idea straight onto it, and this action drafts it
        // immediately. Returning here dropped exactly those ideas the first time
        // the assistant proposed — the idea was visible, then a proposal arrived
        // and took it away.
        //
        // Removing the guard changes nothing else: with nothing drafted at the
        // plan's version, `$drafted` comes back empty and the next line returns
        // `$proposed` anyway.
        $draftedKeys = $this->draftsByIdeaKey($plan)->keys()->all();
        $drafted = $plan->contentIdeas()
            ->where('proposal_version', $plan->assistant_version)
            ->whereIn('idea_key', $draftedKeys)
            ->get();

        if ($drafted->isEmpty()) {
            return $proposed;
        }

        $frozenKeys = $drafted->pluck('idea_key')->all();
        $unwritten = array_values(array_filter(
            $proposed,
            static fn (array $idea): bool => ! in_array($idea['idea_key'], $frozenKeys, true),
        ));

        foreach ($drafted as $idea) {
            $unwritten[] = [
                'idea_key' => $idea->idea_key,
                'title' => $idea->title,
                'kind' => $idea->kind,
                'pillar' => $idea->pillar,
                'thesis' => $idea->thesis,
                'evidence' => $idea->evidence,
                'goal' => $idea->goal,
                'audience' => $idea->audience,
                'angle' => $idea->angle,
                'channels' => $idea->channels,
                'scheduled_for' => $idea->scheduled_for->toDateString(),
            ];
        }

        usort(
            $unwritten,
            static fn (array $left, array $right): int => strcmp($left['scheduled_for'], $right['scheduled_for']),
        );

        return $unwritten;
    }

    /**
     * Drafts follow a stable idea key across proposal versions. The content
     * item keeps pointing at the exact historical idea that produced it; this
     * grouping is the read model that also shows it beside the carried-forward
     * idea in the current version.
     *
     * @return \Illuminate\Support\Collection<string, Collection<int, ContentItem>>
     */
    private function draftsByIdeaKey(ContentPlan $plan): \Illuminate\Support\Collection
    {
        return ContentItem::query()
            ->where('content_plan_id', $plan->getKey())
            ->whereNotNull('content_idea_id')
            ->with('contentIdea')
            ->orderBy('channel_type')
            ->get()
            ->filter(static fn (ContentItem $item): bool => $item->contentIdea !== null)
            ->groupBy(static fn (ContentItem $item): string => $item->contentIdea->idea_key);
    }

    private function assertVersion(ContentPlan $plan, int $expectedVersion): void
    {
        if ($plan->assistant_version !== $expectedVersion) {
            throw new ContentStudioConflict(
                "This proposal changed from version {$expectedVersion} to {$plan->assistant_version}. Refresh before editing it.",
            );
        }
    }

    private function lockKey(ContentPlan $plan): string
    {
        return 'content-studio:'.$plan->project_id.':'.$plan->getKey();
    }

    /** @return array<string, mixed>|null */
    private function decodeObject(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function dateInMonth(mixed $value, Carbon $month): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value || ! $date->isSameMonth($month, true)) {
            return null;
        }

        return $date->toDateString();
    }

    private function text(mixed $value, int $limit): string
    {
        return is_scalar($value) ? Str::limit(trim((string) $value), $limit, '') : '';
    }

    private function untruncatedText(mixed $value, int $limit, string $label): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if (mb_strlen($text) > $limit) {
            throw new InvalidAssistantResponse("{$label} exceeds {$limit} characters.");
        }

        return $text;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return list<string> */
    private function textList(mixed $value, int $maximum, int $limit): array
    {
        $items = [];

        foreach (array_slice($this->list($value), 0, $maximum) as $item) {
            $text = $this->text($item, $limit);

            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function draftSegments(mixed $value, int $maximum, int $limit, string $channel): array
    {
        $raw = $this->list($value);

        if (count($raw) > $maximum) {
            throw new InvalidAssistantResponse("{$channel} has more than {$maximum} segments.");
        }

        $segments = [];

        foreach ($raw as $segment) {
            $text = $this->untruncatedText($segment, $limit, "A {$channel} segment");

            if ($text !== '') {
                $segments[] = $text;
            }
        }

        return $segments;
    }
}
