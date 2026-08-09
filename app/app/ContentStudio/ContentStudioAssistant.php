<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Enums\ChannelType;
use App\Enums\ContentItemType;
use App\Media\HeroImage;
use App\Models\BrandBrief;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\SitePage;
use App\Pipelines\Exceptions\TerminalStepFailure;
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

    /** @var list<string> */
    private const array CHANNELS = [
        ChannelType::Threads->value,
        ChannelType::X->value,
        ChannelType::Instagram->value,
    ];

    public function __construct(private readonly HeroImage $images) {}

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
     * Generate the earliest not-yet-drafted seven-day window.
     *
     * @return array{plan: ContentPlan, created: int, from: string|null, until: string|null}
     */
    public function generateNextBatch(
        ContentPlan $plan,
        ModelSession $models,
        string $operationId,
        bool $initial = false,
    ): array {
        try {
            return Cache::lock($this->lockKey($plan).':drafts', self::DRAFT_LOCK_SECONDS)
                ->block(self::DUPLICATE_WAIT_SECONDS, function () use ($plan, $models, $operationId, $initial): array {
                    $fresh = ContentPlan::query()->whereKey($plan->getKey())->firstOrFail();

                    $alreadyCreated = ContentItem::query()
                        ->where('content_plan_id', $fresh->getKey())
                        ->where('channel_payload->pipeline_run_id', $operationId)
                        ->with('contentIdea')
                        ->get();

                    if ($alreadyCreated->isNotEmpty()) {
                        $dates = $alreadyCreated
                            ->pluck('contentIdea.scheduled_for')
                            ->filter()
                            ->sort()
                            ->values();

                        return [
                            'plan' => $fresh,
                            'created' => $alreadyCreated->count(),
                            'from' => $dates->first()?->toDateString(),
                            'until' => $dates->last()?->toDateString(),
                        ];
                    }

                    if (! $initial && ! $fresh->hasAcceptedAssistantVersion()) {
                        throw new ContentStudioException('Accept the current proposal before generating drafts.');
                    }

                    $ideas = $this->ideasMissingDrafts($fresh);

                    if ($ideas->isEmpty()) {
                        return ['plan' => $fresh, 'created' => 0, 'from' => null, 'until' => null];
                    }

                    /** @var ContentIdea $first */
                    $first = $ideas->first();
                    $from = $first->scheduled_for->copy()->startOfDay();
                    $until = $from->copy()->addDays(6)->endOfDay();
                    $batch = $initial
                        ? $ideas->take(1)->values()
                        : $ideas->filter(
                            static fn (ContentIdea $idea): bool => $idea->scheduled_for->betweenIncluded($from, $until),
                        )->values();

                    $drafts = [];

                    foreach ($batch as $idea) {
                        $missing = array_values(array_diff(
                            $idea->channels,
                            $idea->contentItems->pluck('channel_type')->all(),
                        ));

                        if ($missing === []) {
                            continue;
                        }

                        $drafts[(string) $idea->getKey()] = $this->draftIdea(
                            $fresh,
                            $idea,
                            $missing,
                            $models,
                        );
                    }

                    $createdIds = DB::transaction(function () use (
                        $fresh,
                        $batch,
                        $drafts,
                        $operationId,
                    ): array {
                        $brief = BrandBrief::activeFor($fresh->project);
                        $created = [];

                        foreach ($batch as $idea) {
                            foreach ($drafts[(string) $idea->getKey()] ?? [] as $channel => $draft) {
                                $existing = ContentItem::query()
                                    ->where('content_idea_id', $idea->getKey())
                                    ->where('channel_type', $channel)
                                    ->first();

                                if ($existing !== null) {
                                    continue;
                                }

                                $item = ContentItem::query()->create([
                                    'content_plan_id' => $fresh->getKey(),
                                    'content_idea_id' => $idea->getKey(),
                                    'brand_brief_id' => $brief?->getKey(),
                                    'locale' => $fresh->project->default_locale,
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
                        }

                        return $created;
                    });

                    $this->illustrateDrafts($createdIds);

                    return [
                        'plan' => $fresh->refresh(),
                        'created' => count($createdIds),
                        'from' => $from->toDateString(),
                        'until' => $until->toDateString(),
                    ];
                });
        } catch (LockTimeoutException $e) {
            throw new ContentStudioException(
                'The assistant is still generating this batch. Give it a moment and reload.',
                previous: $e,
            );
        }
    }

    private function buildProposal(
        Project $project,
        ContentPlan $plan,
        ?string $message,
        int $expectedVersion,
        ModelSession $models,
        string $operationId,
    ): ContentPlan {
        $answer = $models->send(new ModelRequest(
            role: 'outline',
            instructions: $this->proposalInstructions(),
            prompt: $this->proposalPrompt($project, $plan, $message),
        ));

        $proposal = $this->normaliseProposal($answer->text, $plan->month);
        $proposal['ideas'] = $this->preserveDraftedIdeas($plan, $proposal['ideas']);

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
            'Plan native expressions of shared ideas for Threads, X, and Instagram. Do not cut an article into three smaller articles.',
            'Threads should invite a real conversation. X should make a compact, useful argument. Instagram should have a visual reason to exist.',
            'Return JSON only, with exactly this shape:',
            '{"summary":"...","site_facts":[{"claim":"...","source":"site analysis|brand brief|site corpus|business data"}],"assumptions":["..."],"objectives":["..."],"pillars":[{"name":"...","purpose":"..."}],"channel_roles":{"threads":"...","x":"...","instagram":"..."},"questions":["..."],"ideas":[{"key":"short-key","date":"YYYY-MM-DD","title":"...","pillar":"...","thesis":"...","evidence":["..."],"goal":"...","audience":"...","angle":"...","channels":["threads","x","instagram"]}]}',
            'Use only dates inside the requested month. Spread the ideas across the month. Keep questions to the two or three unknowns that would materially change the plan.',
        ]);
    }

    private function proposalPrompt(Project $project, ContentPlan $plan, ?string $message): string
    {
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

        $context = [
            'requested_month' => $plan->month->format('Y-m'),
            'target_ideas' => max(8, min(20, $project->weekly_target * 4)),
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
            $channels = array_values(array_intersect(
                self::CHANNELS,
                array_map('strval', $this->list($raw['channels'] ?? [])),
            ));

            $ideas[] = [
                'idea_key' => $key,
                'title' => $title,
                'pillar' => $this->text($raw['pillar'] ?? null, 255),
                'thesis' => $thesis,
                'evidence' => $this->textList($raw['evidence'] ?? [], 20, 1000),
                'goal' => $this->text($raw['goal'] ?? null, 255),
                'audience' => $this->text($raw['audience'] ?? null, 500),
                'angle' => $this->text($raw['angle'] ?? null, 2000) ?: null,
                'channels' => $channels === [] ? self::CHANNELS : $channels,
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
     * @param  list<string>  $channels
     * @return array<string, array{body: string, payload: array<string, mixed>}>
     */
    private function draftIdea(
        ContentPlan $plan,
        ContentIdea $idea,
        array $channels,
        ModelSession $models,
    ): array {
        $lastError = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $answer = $models->send(new ModelRequest(
                role: 'draft',
                instructions: $this->draftInstructions($plan, $idea),
                prompt: $this->draftPrompt($idea, $channels, $lastError),
            ));

            try {
                return $this->normaliseDrafts($answer->text, $idea, $channels);
            } catch (InvalidAssistantResponse $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new InvalidAssistantResponse($lastError);
    }

    private function draftInstructions(ContentPlan $plan, ContentIdea $idea): string
    {
        $brief = BrandBrief::activeFor($plan->project)?->compileToPrompt() ?? '';

        return implode("\n\n", array_filter([
            'You turn one approved content idea into native social drafts. Keep the same thesis and evidence, but do not cross-post the same wording.',
            'Never invent facts. If evidence is absent, write an opinion, question, or framing rather than manufacturing proof.',
            'Threads: one conversational thought, normally one segment, each segment at most 500 characters. Include a visual_brief for one supporting image.',
            'X: one compact post or a justified short thread, each segment at most 280 characters. Include a visual_brief for one supporting image.',
            $this->instagramFormat($idea) === 'carousel'
                ? 'Instagram: a caption plus a visually coherent carousel of at most 10 slides. Slides need short headings and concise bodies.'
                : 'Instagram: a caption paired with one strong editorial image. Include a visual_brief and do not invent carousel slides.',
            $brief,
            "Idea: {$idea->title}\nThesis: {$idea->thesis}",
        ]));
    }

    /** @param list<string> $channels */
    private function draftPrompt(ContentIdea $idea, array $channels, ?string $lastError): string
    {
        $instagram = $this->instagramFormat($idea) === 'carousel'
            ? '"instagram":{"format":"carousel","caption":"...","slides":[{"heading":"...","body":"..."}],"visual_brief":"..."}'
            : '"instagram":{"format":"image","caption":"...","visual_brief":"..."}';

        return implode("\n\n", array_filter([
            'Write only these channels: '.implode(', ', $channels).'.',
            'Pillar: '.$idea->pillar,
            'Goal: '.$idea->goal,
            'Audience: '.$idea->audience,
            'Angle: '.($idea->angle ?? ''),
            'Allowed evidence: '.json_encode($idea->evidence, JSON_UNESCAPED_UNICODE),
            $lastError === null ? null : "The previous answer was invalid: {$lastError}. Correct it.",
            'Return JSON only: {"threads":{"format":"post","segments":["..."],"visual_brief":"..."},"x":{"format":"post|thread","segments":["..."],"visual_brief":"..."},'.$instagram.'}',
        ]));
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, array{body: string, payload: array<string, mixed>}>
     */
    private function normaliseDrafts(string $text, ContentIdea $idea, array $channels): array
    {
        $decoded = $this->decodeObject($text);

        if ($decoded === null) {
            throw new InvalidAssistantResponse('Draft output was not a JSON object.');
        }

        $drafts = [];

        foreach ($channels as $channel) {
            $raw = $decoded[$channel] ?? null;

            if (! is_array($raw)) {
                throw new InvalidAssistantResponse("Draft output omitted {$channel}.");
            }

            if ($channel === ChannelType::Instagram->value) {
                $drafts[$channel] = $this->instagramDraft($raw, $idea);

                continue;
            }

            $limit = $channel === ChannelType::Threads->value ? 500 : 280;
            $maximum = $channel === ChannelType::Threads->value ? 3 : 6;
            $segments = $this->draftSegments($raw['segments'] ?? [], $maximum, $limit, $channel);

            if ($segments === []) {
                throw new InvalidAssistantResponse("{$channel} has no usable text.");
            }

            $format = $this->text($raw['format'] ?? 'post', 30) ?: 'post';
            $drafts[$channel] = [
                'body' => implode("\n\n---\n\n", $segments),
                'payload' => [
                    'source' => 'content_assistant',
                    'content_idea_id' => $idea->getKey(),
                    'format' => $format,
                    'segments' => array_map(
                        static fn (string $segment): array => ['text' => $segment],
                        $segments,
                    ),
                    'visual_brief' => $this->text($raw['visual_brief'] ?? null, 2000),
                ],
            ];
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{body: string, payload: array<string, mixed>}
     */
    private function instagramDraft(array $raw, ContentIdea $idea): array
    {
        $caption = $this->untruncatedText($raw['caption'] ?? null, 2200, 'Instagram caption');

        if ($caption === '') {
            throw new InvalidAssistantResponse('Instagram has no caption.');
        }

        $format = $this->instagramFormat($idea);
        $visual = $this->text($raw['visual_brief'] ?? null, 2000);

        if ($format === 'image') {
            return [
                'body' => $caption,
                'payload' => [
                    'source' => 'content_assistant',
                    'content_idea_id' => $idea->getKey(),
                    'format' => 'image',
                    'caption' => $caption,
                    'visual_brief' => $visual,
                ],
            ];
        }

        $slides = [];

        foreach (array_slice($this->list($raw['slides'] ?? []), 0, 10) as $slide) {
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

        $body = $caption."\n\n".implode("\n\n", array_map(
            static fn (array $slide, int $index): string => sprintf(
                '%d. %s%s',
                $index + 1,
                $slide['heading'],
                $slide['body'] === '' ? '' : "\n{$slide['body']}",
            ),
            $slides,
            array_keys($slides),
        ));

        return [
            'body' => $body,
            'payload' => [
                'source' => 'content_assistant',
                'content_idea_id' => $idea->getKey(),
                'format' => 'carousel',
                'caption' => $caption,
                'slides' => $slides,
                'visual_brief' => $visual,
            ],
        ];
    }

    private function instagramFormat(ContentIdea $idea): string
    {
        return $idea->scheduled_for->day % 2 === 0 ? 'carousel' : 'image';
    }

    /** @param list<string> $itemIds */
    private function illustrateDrafts(array $itemIds): void
    {
        if ($itemIds === [] || ! $this->images->isConfigured()) {
            return;
        }

        foreach (ContentItem::query()->whereKey($itemIds)->get() as $item) {
            $payload = $item->channel_payload ?? [];
            $brief = $this->text($payload['visual_brief'] ?? null, 2000);

            try {
                $made = $this->images->for(
                    $item,
                    $item->title,
                    $brief !== '' ? $brief : Str::limit((string) $item->body_markdown, 500),
                );
            } catch (TerminalStepFailure) {
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
        }
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
            'pillar' => $idea->pillar,
            'thesis' => $idea->thesis,
            'evidence' => $idea->evidence,
            'goal' => $idea->goal,
            'audience' => $idea->audience,
            'angle' => $idea->angle,
            'channels' => $idea->channels,
        ];
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
