<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Subscriptions;
use App\Enums\ChannelType;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Http\Requests\OnboardingStepRequest;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\Project;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Onboarding\SiteAnalyst;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Duty\DutyHours;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Creating a project: URL in, a running engine out.
 *
 * The wizard is six steps and the first one does the work — everything after it
 * is the operator correcting what reading the site suggested, which is a much
 * shorter job than filling six forms from nothing.
 *
 * A project row exists from the first step and carries the answers as they are
 * given. A closed tab is then a resumable setup rather than a lost one, and the
 * analysis has somewhere to live that is not a session.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly ProjectManager $projects,
        private readonly CurrentProject $current,
        private readonly ChannelPublisherRegistry $publishers,
        private readonly Subscriptions $subscriptions,
    ) {}

    /** The wizard itself, resuming whatever draft the operator has open. */
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $draft = $user->projects()
            ->where('onboarding_status', OnboardingStatus::Draft->value)
            ->latest()
            ->first();

        return Inertia::render('onboarding/wizard', [
            'draft' => $draft === null ? null : $this->toProps($draft),
            'channelTypes' => array_map(static fn (ChannelType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'is_social' => $type->isSocial(),
            ], ChannelType::cases()),
        ]);
    }

    /**
     * Step 1. Read the site, then hand back what we think the business is.
     *
     * Synchronous, and that is deliberate: the operator is watching a spinner
     * they asked for, and a job plus polling would be more machinery for a
     * wait they have already agreed to.
     */
    public function analyse(Request $request, SiteAnalyst $analyst): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_id' => ['sometimes', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            ['snapshot' => $snapshot, 'analysis' => $analysis] = $analyst->analyse($validated['url']);
        } catch (RuntimeException $e) {
            // A site that will not load is the operator's problem to fix, and
            // they can only fix it if we say which site and what happened.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'We could not read that site. Try again in a moment.'], 500);
        }

        $project = $this->draftFor($user, $validated['project_id'] ?? null);

        $project->forceFill([
            'name' => $analysis->name !== '' ? $analysis->name : $project->name,
            'website_url' => $snapshot->url,
            'sitemap_url' => $snapshot->sitemapUrl,
            'site_analysis' => $analysis->toArray(),
            'default_locale' => $analysis->language,
            'locales' => [$analysis->language],
            'market' => $analysis->market,
            'is_ymyl' => $analysis->isYmyl,
            'competitors' => $analysis->competitors,
            'research_seeds' => $analysis->seedKeywords,
        ])->save();

        return response()->json(['project' => $this->toProps($project)]);
    }

    /** Steps 2–7. Saved as they are answered. */
    public function save(OnboardingStepRequest $request, Project $project): JsonResponse
    {
        $this->authoriseDraft($request, $project);

        $validated = $request->validated();

        $project->forceFill([
            'onboarding' => [...$project->onboarding, $validated['step'] => $validated['answers']],
        ])->save();

        $this->applyStep($project, $validated['step'], $validated['answers']);

        return response()->json(['project' => $this->toProps($project->refresh())]);
    }

    /**
     * The last step: write the brief, connect the channels, start the engine.
     */
    public function launch(Request $request, Project $project, ProjectLaunch $launch): RedirectResponse
    {
        $this->authorise($request, $project);

        /** @var User $user */
        $user = $request->user();

        // The row lock makes the status transition a single winner. Two final
        // clicks can arrive before either response returns; only the request
        // that sees Draft while holding this lock may create the brief,
        // channels, and research run.
        $started = DB::transaction(function () use ($project, $launch, $user): ?bool {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->onboarding_status !== OnboardingStatus::Draft) {
                return false;
            }

            // A wizard that was never answered would compile a brief with no
            // positioning and no audience, and every article for the next
            // month would be written from it.
            if (trim($this->positioningFor($locked)) === '') {
                return null;
            }

            $this->current->run($locked, function () use ($locked): void {
                $this->writeBrief($locked);
                $this->connectChannels($locked);
            });

            // The free window starts here rather than at registration, and
            // this is the line that decides it: the clock should start when the
            // engine does. Somebody who signs up on Friday and finishes the
            // wizard on Monday has not had a trial over the weekend.
            //
            // Inside the lock and before `begin()`, because `begin()` starts
            // research — which is spend, and spend on a project with no
            // subscription is refused. A trial minted afterwards would leave
            // the first run of every new project's life gated out.
            //
            // `startTrial()` is idempotent, so the second final click of a
            // double-press cannot mint a second window. It also returns the
            // existing subscription untouched, which is what makes relaunching
            // a project safe.
            $this->subscriptions->startTrial($locked, $user);

            $launch->begin($locked);

            return true;
        });

        if ($started === null) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Tell us what the business does before we start writing about it.',
            ]);

            return back();
        }

        $project->refresh();

        $this->projects->switchTo($user, $project);

        if ($started === false) {
            return to_route('home.index');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$project->name} is set up. Research and the first social content proposal have started.",
        ]);

        return to_route('home.index');
    }

    /**
     * A project exists from step one, so the analysis and the answers have
     * somewhere to live and a closed tab is resumable.
     */
    private function draftFor(User $user, ?string $projectId): Project
    {
        if ($projectId !== null) {
            $existing = $user->projects()
                ->whereKey($projectId)
                ->where('onboarding_status', OnboardingStatus::Draft->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($user): Project {
            $project = Project::query()->create([
                'name' => 'New project',
                'slug' => 'project-'.Str::lower(Str::random(8)),
                'timezone' => 'UTC',
                'default_locale' => 'en',
                'locales' => ['en'],
                'status' => ProjectStatus::Active,
                'onboarding_status' => OnboardingStatus::Draft,
                'weekly_target' => (int) config('onboarding.defaults.weekly_target', 3),
                'research_seeds' => [],
            ]);

            $project->users()->attach($user, ['role' => 'owner']);

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function applyStep(Project $project, string $step, array $answers): void
    {
        $changes = match ($step) {
            'market' => [
                'market' => (string) ($answers['market'] ?? $project->market),
                'default_locale' => (string) ($answers['language'] ?? $project->default_locale),
                'locales' => array_values(array_unique([
                    (string) ($answers['language'] ?? $project->default_locale),
                    ...array_map('strval', $answers['extra_languages'] ?? []),
                ])),
                'timezone' => (string) ($answers['timezone'] ?? $project->timezone),
                // Canonicalised through the value object on the way in, so the
                // column holds days in week order with touching windows merged
                // and unusable ranges dropped — never the half-typed shape the
                // wizard happened to post. Unanswered leaves what is there,
                // which for a new project is nothing, which is "never on duty".
                'duty_hours' => DutyHours::fromArray(
                    is_array($answers['duty_hours'] ?? null) ? $answers['duty_hours'] : $project->duty_hours,
                )->toArray(),
            ],
            'business' => [
                'name' => (string) ($answers['name'] ?? $project->name),
            ],
            'voice' => [
                'sitemap_url' => ($answers['sitemap_url'] ?? null) ?: $project->sitemap_url,
                'authors' => $this->authorsFrom($answers),
            ],
            'competitors' => [
                'competitors' => array_values(array_map('strval', $answers['competitors'] ?? [])),
            ],
            'settings' => [
                'article_settings' => $answers,
                'weekly_target' => max(1, (int) ($answers['weekly_target'] ?? $project->weekly_target)),
                // A YMYL project cannot opt out of review, whatever the form
                // sent: the checkbox is disabled in the UI, and a disabled
                // checkbox is a suggestion rather than a rule.
                'autopublish' => ! $project->is_ymyl && (bool) ($answers['autopublish'] ?? false),
            ],
            default => [],
        };

        if ($changes !== []) {
            $project->forceFill($changes)->save();
        }
    }

    /**
     * A named author or nobody. YMYL projects need a real byline; the rest are
     * published by the brand, and an empty author entry is worse than none.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array{name: string, title: string}>
     */
    private function authorsFrom(array $answers): array
    {
        $name = trim((string) ($answers['author_name'] ?? ''));

        if ($name === '') {
            return [];
        }

        return [['name' => $name, 'title' => (string) ($answers['author_title'] ?? '')]];
    }

    /**
     * @return list<string>
     */
    private function oneExample(mixed $value): array
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? [] : [$text];
    }

    /**
     * The brief, from the analysis as the operator corrected it.
     *
     * Through revise() like every other brief, so it gets a version and a
     * change note and the history starts the way it will continue.
     */
    private function writeBrief(Project $project): void
    {
        $answers = $project->onboarding;
        $analysis = $project->site_analysis;

        $business = $answers['business'] ?? [];
        $voice = $answers['voice'] ?? [];

        BrandBrief::revise($project, [
            'positioning' => $this->positioningFor($project),
            'audience' => implode("\n", array_map(
                static fn (mixed $item): string => '- '.(string) $item,
                $business['audiences'] ?? $analysis['audiences'] ?? [],
            )),
            'tone' => (string) ($voice['tone'] ?? $analysis['tone'] ?? ''),
            'visual_language' => (string) ($voice['visual_language'] ?? $analysis['visual_language'] ?? ''),
            'forbidden_topics' => array_map('strval', $voice['forbidden'] ?? $analysis['forbidden'] ?? []),
            'examples_liked' => $this->oneExample($voice['example_liked'] ?? null),
            'examples_disliked' => $this->oneExample($voice['example_disliked'] ?? null),
            'competitors' => $project->competitors,
        ], 'Compiled from onboarding.');
    }

    /**
     * What the brief will be written from — the operator's own words if they
     * gave any, otherwise what reading the site suggested.
     */
    private function positioningFor(Project $project): string
    {
        $business = $project->onboarding['business'] ?? [];

        return (string) ($business['description'] ?? $project->site_analysis['description'] ?? '');
    }

    /**
     * §8: a project is one content layer across a site and its social channels,
     * so the wizard connects them together and they share one brief.
     */
    private function connectChannels(Project $project): void
    {
        /** @var array<string, mixed> $answers */
        $answers = $project->onboarding['channels'] ?? [];

        $endpoint = (string) ($answers['webhook_endpoint'] ?? '');

        if ($endpoint !== '') {
            $website = Channel::query()->updateOrCreate(
                ['name' => 'Website'],
                [
                    'type' => ChannelType::Webhook,
                    'config' => ['endpoint' => $endpoint],
                    'secret' => (string) ($answers['webhook_secret'] ?? Str::random(48)),
                    'is_enabled' => true,
                ],
            );

            // A signed test request, now rather than on the first article. The
            // endpoint field takes whatever is typed into it, and a page on the
            // operator's own site answers 405 to a POST — which used to surface
            // a month later as an article that said Published and was not.
            $this->publishers->for(ChannelType::Webhook)->ping($website, $project);
        }

        foreach (array_map('strval', $answers['social'] ?? []) as $type) {
            $channelType = ChannelType::tryFrom($type);

            if ($channelType === null || ! $channelType->isSocial()) {
                continue;
            }

            Channel::query()->updateOrCreate(
                ['name' => $channelType->label()],
                ['type' => $channelType, 'config' => [], 'is_enabled' => true],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toProps(Project $project): array
    {
        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'slug' => $project->slug,
            'website_url' => $project->website_url,
            'sitemap_url' => $project->sitemap_url,
            'market' => $project->market,
            'language' => $project->default_locale,
            'is_ymyl' => $project->is_ymyl,
            'competitors' => $project->competitors,
            'seed_keywords' => $project->research_seeds,
            'weekly_target' => $project->weekly_target,
            'duty_hours' => $project->dutyHours()->toArray(),
            'analysis' => $project->site_analysis,
            'onboarding' => $project->onboarding,
        ];
    }

    private function authorise(Request $request, Project $project): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(
            $user->projects()->whereKey($project->getKey())->wherePivot('role', 'owner')->exists(),
            404,
        );
    }

    /**
     * A launched project is past the wizard. Answering a step against one would
     * edit it without any of the validation the settings form applies.
     */
    private function authoriseDraft(Request $request, Project $project): void
    {
        $this->authorise($request, $project);

        abort_unless($project->onboarding_status === OnboardingStatus::Draft, 404);
    }
}
