<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanSelection;
use App\Billing\Subscriptions;
use App\Billing\TrialEligibility;
use App\Enums\ChannelType;
use App\Enums\OnboardingStatus;
use App\Enums\ProjectStatus;
use App\Http\Requests\OnboardingStepRequest;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Onboarding\ProjectLaunch;
use App\Onboarding\SiteAnalyst;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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
    /**
     * How long an article is, when nobody says.
     *
     * Long enough to cover a question properly and short enough that a reader
     * finishes it. The wizard used to ask, which put a decision about prose in
     * front of somebody who had come to buy an outcome.
     */
    private const int DEFAULT_TARGET_WORDS = 1400;

    public function __construct(
        private readonly ProjectManager $projects,
        private readonly CurrentProject $current,
        private readonly ChannelPublisherRegistry $publishers,
        private readonly TrialEligibility $trials,
        private readonly BillingProvider $provider,
        private readonly PlanCatalog $plans,
        private readonly PlanSelection $selection,
        private readonly Subscriptions $subscriptions,
        private readonly ProjectLaunch $launcher,
    ) {}

    /** The wizard itself, resuming whatever draft the operator has open. */
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $draft = $user->projects()
            ->where('onboarding_status', OnboardingStatus::Draft->value)
            ->whereNull('projects.archived_at')
            ->latest()
            ->first();

        return Inertia::render('onboarding/wizard', [
            'draft' => $draft === null ? null : $this->toProps($draft),
            'selectedPlan' => $this->selection->selected($request, $draft)->toArray(),
            'plans' => array_map(static fn (Plan $plan): array => $plan->toArray(), $this->plans->selfServe()),
            'trialDays' => $this->plans->trialDays(),
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
        if ($validated['step'] === 'market') {
            $offer = app(Entitlements::class)->for($project)->plan ?? $this->selection->selected($request, $project);
            $locales = array_unique([(string) $validated['answers']['language'], ...($validated['answers']['extra_languages'] ?? [])]);
            if ($offer->limit('locales') !== null && count($locales) > $offer->limit('locales')) {
                throw ValidationException::withMessages(['answers.extra_languages' => 'This plan includes one language. Choose the language for your website.']);
            }
        }
        if ($validated['step'] === 'offer') {
            $plan = $this->selection->validate((string) $validated['answers']['key']);
            $request->session()->put(PlanSelection::SESSION_KEY, $this->selection->identity($plan));
            $project->weekly_target = $plan->weeklyTarget() ?? 7;
        }

        $project->forceFill([
            'onboarding' => [...$project->onboarding, $validated['step'] => $validated['answers']],
        ])->save();

        $this->applyStep($project, $validated['step'], $validated['answers']);

        return response()->json(['project' => $this->toProps($project->refresh())]);
    }

    /**
     * The last step: write the brief, connect the channels, start the engine.
     */
    public function launch(Request $request, Project $project): SymfonyResponse
    {
        $this->authorise($request, $project);

        /** @var User $user */
        $user = $request->user();

        $plan = $this->selection->validate($this->selection->selected($request, $project)->key);
        if (count($project->locales) > ($plan->limit('locales') ?? PHP_INT_MAX)) {
            throw ValidationException::withMessages(['plan' => 'Select one website language before starting this plan.']);
        }

        // Before anything is written, because everything below this line
        // spends money: the brief is a model call, and `begin()` starts
        // research. A refusal has to cost nothing.
        $refusal = $this->trials->refusalFor($user, $project);

        if ($refusal !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $refusal]);

            return back();
        }

        // The row lock makes the status transition a single winner. Two final
        // clicks can arrive before either response returns; only the request
        // that sees Draft while holding this lock may create the brief,
        // channels, and research run.
        $started = DB::transaction(function () use ($project, $plan): ?bool {
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

            $this->current->run($locked, function () use ($locked, $plan): void {
                $this->writeBrief($locked);
                $this->connectChannels($locked, $plan);
            });

            // Marked as launching, and nothing started.
            //
            // The engine begins when Stripe confirms the trial, not here — see
            // the redirect below. Flipping the status inside the lock is what
            // makes a double-pressed final button safe: the guard above reads
            // Draft, so the second press finds Launching and does nothing.
            $locked->forceFill(['onboarding_status' => OnboardingStatus::Launching])->save();

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
            // Already launched. Whatever this second press was, it was not the
            // first one.
            return to_route('home.index');
        }

        // The sample, then the card — in that order.
        //
        // This used to go straight out to Stripe, on the reasoning that
        // somebody who has just watched us read their homepage is at their
        // most convinced. They are not: what they have seen at that point is a
        // form that filled itself in. The card question landed before a single
        // sentence of what they came to buy existed, and a checkout that was
        // closed left the project sitting at Launching behind an amber banner
        // that read, to the person who had just finished setting it up, as an
        // error.
        //
        // So the engine starts now, on a bounded card-free allowance: the
        // month's plan of topics and one finished article, both made from
        // their own site. {@see \App\Billing\Subscriptions::startPreview()}
        // holds the bounds and the reasoning for each one. Nothing it makes is
        // published — a sample is read, never sent — and when it is done the
        // engine stops itself and asks for the card, with the work on the
        // screen behind the question.
        //
        // The trial is untouched by this and still begins at the checkout,
        // which is the point: the free days are worth something once somebody
        // has decided we are worth three of them.
        $preview = $this->startPreviewFor($user, $project, $plan);

        if ($preview !== null) {
            $this->current->run($project, fn () => $this->launcher->begin($project));

            return to_route('home.index');
        }

        // No preview to be had — this site has already had one, this
        // deployment's price list has none, or another launch was holding the
        // decision when we asked. Straight to the card, as before, rather than
        // a launch nothing is entitled to run.
        try {
            return Inertia::location($this->provider->checkoutUrl(
                $user,
                $project,
                $plan,
                route('home.index'),
                // Free days only if this site has not had them. The eligibility
                // check above already refused a *second concurrent* trial; this
                // is the same question asked of the checkout, so relaunching a
                // project whose trial has been and gone buys a plan rather than
                // starting the window again.
                withTrial: $this->trials->mayHaveATrial($project),
            ));
        } catch (Throwable $e) {
            // The engine has not started and no card was taken, so nothing is
            // half-done — but the operator is looking at a wizard that appears
            // to have swallowed their last click.
            report($e);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'We could not open the checkout. Your project is saved — add a card from Plan & usage to start it.',
            ]);

            return to_route('home.index');
        }
    }

    /**
     * Ask whether a sample is allowed and mint it, with nobody in between.
     *
     * {@see TrialEligibility::mayHaveAPreview()} reads rows that
     * {@see Subscriptions::startPreview()} then writes, and between the two
     * there is a gap wide enough to drive a second launch through. The
     * `lockForUpdate` above does not close it: that lock is on *this*
     * project's row, so it serialises two presses of one final button and
     * nothing else. Two different draft projects hold two different rows, both
     * read "no sample yet", and both start an engine — five dollars and two
     * research runs, spent before anybody has been asked for a card, which is
     * the exact bound the eligibility rules exist to hold.
     *
     * **Two locks, because there are two rules and either can be broken on its
     * own.** One account with two drafts open violates the one-at-a-time rule
     * while touching two different hostnames; two accounts pointed at one site
     * violate the one-per-site rule while sharing no account. A lock on either
     * key alone lets the other race through, so both are taken.
     *
     * Always account first, then site. Not because that order is better —
     * because it is fixed. Two requests reaching for the same pair in opposite
     * orders is the whole recipe for a deadlock, and a single agreed sequence
     * is what makes a cycle between these two keys impossible to construct.
     *
     * **A lock we cannot get is not an error.** It means another launch is
     * mid-decision under one of these rules, and the likeliest truth is that
     * this one is the launch that rule would have refused anyway. Saying so
     * out loud would put a red banner on the last click of a wizard that has
     * just worked; instead this answers null and {@see launch()} falls through
     * to the checkout — the pre-existing path, and already what a `false` from
     * the eligibility check does.
     */
    private function startPreviewFor(User $user, Project $project, Plan $plan): ?ProjectSubscription
    {
        $host = TrialEligibility::hostOf((string) $project->website_url);

        // Nothing to serialise and nothing to allow: a project with no
        // readable hostname cannot be held to the per-site rule, and
        // `mayHaveAPreview()` refuses it for that reason.
        if ($host === null) {
            return null;
        }

        /** @var list<Lock> $held */
        $held = [];

        try {
            foreach (['preview-launch-account:'.$user->getKey(), 'preview-launch-site:'.$host] as $key) {
                $lock = Cache::lock($key, 60);

                if (! $lock->get()) {
                    return null;
                }

                $held[] = $lock;
            }

            // Asked again here rather than before the locks, because an answer
            // given outside them is the answer this method exists to distrust.
            return $this->trials->mayHaveAPreview($user, $project)
                ? $this->subscriptions->startPreview($project, $plan, $user)
                : null;
        } finally {
            // Released in reverse, and in a `finally` so that a failure to
            // take the second lock cannot strand the first one for a minute.
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
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
                ->whereNull('projects.archived_at')
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
                'weekly_target' => $this->selection->selected(request())->weeklyTarget() ?? 7,
                'onboarding' => ['offer' => $this->selection->identity($this->selection->selected(request()))],
                'autopublish' => true,
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
            ],
            'business' => [
                'name' => (string) ($answers['name'] ?? $project->name),
            ],
            'voice' => [
                'sitemap_url' => ($answers['sitemap_url'] ?? null) ?: $project->sitemap_url,
                'authors' => $this->authorsFrom($answers),
            ],
            // Asked in the publishing step now, beside the other questions
            // about the website rather than between two about its voice.
            'channels' => [
                'sitemap_url' => ($answers['sitemap_url'] ?? null) ?: $project->sitemap_url,
            ],
            'competitors' => [
                'competitors' => array_values(array_map('strval', $answers['competitors'] ?? [])),
            ],
            'settings' => [
                // The publishing question was just answered, so record that it
                // was. This used to be stamped only when the engine started,
                // which for a project whose checkout was abandoned was never —
                // so the dashboard greeted somebody who had chosen a
                // publishing preference thirty seconds earlier with a card
                // telling them to go and choose one.
                'onboarding' => [
                    ...$project->onboarding,
                    'article_automation_started_at' => $project->onboarding['article_automation_started_at'] ?? now()->toIso8601String(),
                ],
                // Length is no longer asked during setup — it is a writing
                // preference, not a signup decision, and it is on the project
                // settings screen for anybody who wants a different one. The
                // installation default is kept here so the column holds a
                // number rather than nothing.
                'article_settings' => [
                    'target_words' => (int) ($project->article_settings['target_words'] ?? self::DEFAULT_TARGET_WORDS),
                    ...$answers,
                ],
                'weekly_target' => max(1, (int) ($answers['weekly_target'] ?? $project->weekly_target)),
                // A YMYL project cannot opt out of review, whatever the form
                // sent: the checkbox is disabled in the UI, and a disabled
                // checkbox is a suggestion rather than a rule.
                'autopublish' => ! $project->is_ymyl && (bool) ($answers['autopublish'] ?? $project->autopublish),
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
            ...$this->coloursFor($project),
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
     * The site's own colours, where reading it found any.
     *
     * Applied here rather than offered later, because the first illustrated
     * article is written within the hour and the Brand brief screen is
     * somewhere nobody has been yet — so the alternative to this is a month of
     * pictures in the installation's default navy for a business that has
     * never been asked. The wizard shows these on the way past, and the brief
     * screen still owns changing them.
     *
     * Empty when the site was unreadable or had no palette worth the name, and
     * an empty array leaves {@see BrandBrief}'s own defaults where they are.
     *
     * @return array<string, string>
     */
    private function coloursFor(Project $project): array
    {
        $palette = $project->site_analysis['palette'] ?? null;

        if (! is_array($palette)) {
            return [];
        }

        $hex = static fn (mixed $value): ?string => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1
            ? strtolower($value)
            : null;

        $fill = $hex($palette['fill'] ?? null);
        $ink = $hex($palette['ink'] ?? null);

        // Both or neither. A brand colour with the default ink on it is how
        // you get white text on a pale background.
        if ($fill === null || $ink === null) {
            return [];
        }

        return [
            'brand_colour' => $fill,
            'brand_ink' => $ink,
            ...($hex($palette['accent'] ?? null) === null ? [] : ['brand_accent' => $hex($palette['accent'])]),
        ];
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
     * The site the project publishes to, connected from the wizard's answers so
     * the first article has somewhere to go.
     */
    private function connectChannels(Project $project, Plan $plan): void
    {
        /** @var array<string, mixed> $answers */
        $answers = $project->onboarding['channels'] ?? [];

        // Only the custom-site answer carries an address. Somebody who chose
        // WordPress, or chose to decide later, must not have a channel made
        // from a URL they typed and then moved away from.
        $endpoint = ($answers['destination'] ?? 'custom') === 'custom'
            ? (string) ($answers['webhook_endpoint'] ?? '')
            : '';

        // The plan's channel limit, checked here as well as in
        // `ChannelController::store`, the other place a channel is made. A
        // site that does not fit is not connected, and the launch goes ahead
        // anyway: the channels page is where one channel is swapped for another.
        $limit = $plan->limit('channels');
        $fits = $limit === null
            || Channel::query()->where('name', '!=', 'Website')->count() < $limit;

        if ($endpoint !== '' && $fits) {
            $website = Channel::query()->updateOrCreate(
                ['name' => 'Website'],
                [
                    'type' => ChannelType::Webhook,
                    'config' => ['endpoint' => $endpoint],
                    'secret' => (string) ($answers['webhook_secret'] ?? Str::random(48)),
                    'is_enabled' => true,
                    'autopublish' => $project->autopublish,
                ],
            );

            // A signed test request, now rather than on the first article. The
            // endpoint field takes whatever is typed into it, and a page on the
            // operator's own site answers 405 to a POST — which used to surface
            // a month later as an article that said Published and was not.
            $this->publishers->for(ChannelType::Webhook)->ping($website, $project);
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
            'autopublish' => $project->autopublish,
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
