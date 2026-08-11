<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Enums\ChannelType;
use App\Enums\ContentItemType;
use App\Enums\PostKind;
use App\Media\CarouselPanels;
use App\Media\HeroImage;
use App\Media\SocialImage;
use App\Models\Asset;
use App\Models\BrandBrief;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\SitePage;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\SocialDraft\DraftCandidates;
use App\Pipelines\Steps\SocialDraft\FactCheckPost;
use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Support\Brand\VisualStyle;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ChannelPostScore;
use App\Support\Social\ContentMix;
use App\Support\Social\PostFormat;
use App\Support\Social\SocialImagePrompt;
use App\Support\Social\StudioPostGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    public function __construct(private readonly SocialImage $images) {}

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
    ): array {
        try {
            return Cache::lock("content-studio:idea:{$idea->getKey()}", self::DRAFT_LOCK_SECONDS)
                ->block(self::DUPLICATE_WAIT_SECONDS, function () use ($idea, $models, $operationId): array {
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
     * @return array{variants: int, cost: int}
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

        $made = $this->images->variants(
            $item,
            $playbook,
            SocialImagePrompt::fromFields($fields, $this->imageSubject($item)),
            $variants,
            BrandBrief::activeFor($item->project),
            $item->contentIdea?->kind->shot(),
        );

        foreach ($made as $picture) {
            $models->spend($picture['cost'], $picture['provider'], $picture['model']);
        }

        $item->forceFill(['channel_payload' => $payload])->save();

        return [
            'variants' => count($made),
            'cost' => array_sum(array_column($made, 'cost')),
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

        /** @var array{summary: string, strategy: array<string, mixed>, ideas: list<array<string, mixed>>} $proposal */
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
            'Threads should invite a real conversation. X should make a compact, useful argument. Instagram should have a visual reason to exist.',

            // The kinds, and the reason they are the first thing decided about
            // an idea rather than a label attached to a finished one. A plan
            // written without them comes back as twenty how-tos every time —
            // which is what it did — because "useful" is the safest thing a
            // model can be, and the channels do not reward safe.
            'Every idea has a kind, and the kind is a decision you make before the subject, not a label '
                .'you add after. The five:',
            '- take: an opinion you are willing to be disagreed with about.',
            '- how_to: teaching — how the thing is actually done.',
            '- proof: a result, a case, a before and after, from the supplied evidence only.',
            '- behind: the work customers never see — the standard, the step everybody skips.',
            '- offer: a direct offer. Rare, and capped.',

            // The channel rule. An idea that goes everywhere is an idea written
            // once and trimmed twice, which is the cross-posting this whole
            // engine argues against — arranged at the planning step, where no
            // amount of care in the drafting step can undo it.
            'The kind decides where the idea belongs, and no idea goes to all three channels. '
                .'take → threads, x. how_to → instagram, x. proof → instagram, threads. '
                .'behind → instagram, threads. offer → instagram. Give the channels of the kind you chose, '
                .'and pick a kind because the idea is that, not because you wanted the channels.',

            'Write every idea angle for the channels it is actually going to. Give each channel a distinct '
                .'execution, not one shared paragraph.',
            'Return JSON only, with exactly this shape:',
            '{"summary":"...","site_facts":[{"claim":"...","source":"site analysis|brand brief|site corpus|business data"}],"assumptions":["..."],"objectives":["..."],"pillars":[{"name":"...","purpose":"..."}],"channel_roles":{"threads":"...","x":"...","instagram":"..."},"questions":["..."],"ideas":[{"key":"short-key","date":"YYYY-MM-DD","title":"...","kind":"take|how_to|proof|behind|offer","pillar":"...","thesis":"...","evidence":["..."],"goal":"...","audience":"...","angle":"...","channels":["threads","x"]}]}',
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

        $context = [
            'requested_month' => $plan->month->format('Y-m'),
            'target_ideas' => $targetIdeas,
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
            'strategy' => [
                'site_facts' => $facts,
                'assumptions' => $this->textList($decoded['assumptions'] ?? [], 20, 1000),
                'objectives' => $this->textList($decoded['objectives'] ?? [], 20, 1000),
                'pillars' => $pillars,
                'channel_roles' => $channelRoles,
                'questions' => $this->textList($decoded['questions'] ?? [], 5, 1000),
            ],
            'ideas' => $ideas,
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
    ): array {
        return DB::transaction(function () use ($plan, $idea, $drafts, $brief, $operationId): array {
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

        foreach ($channels as $channel) {
            $draft = $this->draftChannel(
                ChannelPlaybook::for(ChannelType::from($channel)),
                $plan,
                $idea,
                $models,
                $brief,
                $siblings,
                $spent,
            );

            $drafts[$channel] = $draft;
            $spent[] = (string) ($draft['payload']['angle'] ?? '');

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
            $this->writePool($playbook, $plan, $idea, $models, $brief, $siblings, $spent),
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
                    prompt: $this->channelPrompt($playbook, $idea, $angle, $correction, $siblings),
                ));

                try {
                    $pool[] = $this->parseCandidate($answer->text, $playbook, $idea, $angle);

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
     */
    private function channelPrompt(
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        string $angle,
        ?string $correction,
        array $siblings = [],
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
                ? '{"caption":"...","slides":[{"heading":"...","body":"..."}],'.$visual.'}'
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
                ? 'At most 10 slides, each with a short heading and a concise body.'
                : null,
            'The visual fields describe one photograph to go with this post: what is in it, how it is '
                .'framed, what is happening, where, in what style, and in what light. Be specific — a '
                .'vague brief produces a stock picture. Describe something small and near rather than a '
                .'whole room: hands, a tool, one surface, one object being used. Do not use the words '
                .'premium, elegant, editorial, luxury, pristine, sleek or minimalist — they produce a '
                .'showroom. Never ask for text, words or logos in the image.',
            'Reply with JSON and nothing else: '.$shape,
        ]));
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
     */
    private function parseCandidate(
        string $text,
        ChannelPlaybook $playbook,
        ContentIdea $idea,
        string $angle,
    ): StudioCandidate {
        $decoded = $this->decodeObject($text);

        if ($decoded === null) {
            throw new InvalidAssistantResponse('Draft output was not a JSON object.');
        }

        $visual = is_array($decoded['visual'] ?? null) ? $decoded['visual'] : [];
        $channel = $playbook->channel->value;

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

        $slides = [];

        foreach (array_slice($this->list($decoded['slides'] ?? []), 0, 10) as $slide) {
            if (! is_array($slide)) {
                continue;
            }

            $heading = $this->untruncatedText($slide['heading'] ?? null, 120, 'Instagram slide heading');
            $body = $this->untruncatedText($slide['body'] ?? null, 500, 'Instagram slide body');

            if ($heading !== '' || $body !== '') {
                $slides[] = ['heading' => $heading, 'body' => $body];
            }
        }

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
        if ($itemIds === [] || ! $this->images->isConfigured()) {
            return;
        }

        foreach (ContentItem::query()->whereKey($itemIds)->with('contentIdea')->get() as $item) {
            $payload = $item->channel_payload ?? [];
            $playbook = ChannelPlaybook::for(ChannelType::from((string) $item->channel_type));

            $prompt = is_array($payload['visual'] ?? null) && $payload['visual'] !== []
                ? SocialImagePrompt::fromFields($payload['visual'], $this->imageSubject($item))
                : SocialImagePrompt::fromBrief(null, $this->imageSubject($item));

            try {
                $made = $this->images->for($item, $playbook, $prompt, $brief, $item->contentIdea?->kind->shot());
            } catch (TerminalStepFailure) {
                // A provider that will not draw must not lose a post that is
                // already written and paid for. An unillustrated draft is a
                // weaker draft; a failed batch is no drafts at all.
                continue;
            }

            if ($made === null) {
                continue;
            }

            if (isset($payload['segments'][0]) && is_array($payload['segments'][0])) {
                $payload['segments'][0]['asset_id'] = (string) $made['asset']->getKey();
            }

            $payload['asset_id'] = (string) $made['asset']->getKey();
            $item->forceFill(['channel_payload' => $payload])->save();

            // Per picture rather than per token, and only when one was actually
            // bought — a hero already on the unit reports no provider. Without
            // this the Studio's images never reached §6's cost rows at all.
            $models?->spend($made['cost'], $made['provider'], $made['model']);

            $this->drawPanels($item, $playbook, $payload, $brief);
        }
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
        if ($plan->assistant_version === 0) {
            return $proposed;
        }

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
