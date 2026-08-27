<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Enums\BillingStatus;
use App\Enums\ChannelType;
use App\Enums\OnboardingStatus;
use App\Enums\PipelineRunStatus;
use App\Models\BrandBrief;
use App\Models\Channel;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use App\Onboarding\Contracts\SiteReader;
use App\Onboarding\FakeSiteReader;
use App\Pipelines\Jobs\RunStepJob;
use App\Support\Tenancy\CurrentProject;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    private const string ANSWER = <<<'TEXT'
    NAME: Cleaning Point
    DESCRIPTION: A Lisbon home-cleaning business. It serves flat owners and long-stay residents.
    AUDIENCES: Lisbon flat owners | short-term hosts
    TONE: Plain and practical, first person plural.
    VISUAL: Daylight, real flats.
    COMPETITORS: helpling.pt | zaask.pt
    KEYWORDS: limpeza lisboa | end of tenancy cleaning lisbon
    FORBIDDEN: medical claims about cleaning products
    LANGUAGE: en
    MARKET: pt
    YMYL: no
    TEXT;

    #[Test]
    public function reading_a_site_creates_a_draft_the_operator_can_come_back_to(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk()
            ->assertJsonPath('project.name', 'Cleaning Point')
            ->assertJsonPath('project.market', 'pt')
            // The page's own `lang` is a fact and the model's guess is not, so
            // the fake reader's pt-PT beats the answer's `en`.
            ->assertJsonPath('project.language', 'pt-PT')
            ->assertJsonPath('project.competitors', ['helpling.pt', 'zaask.pt'])
            // Not null. The database defaults these, but a model that just
            // created its own row has never read them back — and the wizard
            // indexes into `onboarding` on the very next render.
            ->assertJsonPath('project.onboarding', [])
            ->assertJsonPath('project.seed_keywords', [
                'limpeza lisboa',
                'end of tenancy cleaning lisbon',
            ]);

        $project = Project::query()->firstOrFail();

        $this->assertSame(OnboardingStatus::Draft, $project->onboarding_status);
        $this->assertTrue($operator->projects()->whereKey($project->getKey())->exists());

        // Reopening the wizard resumes rather than starting again — otherwise a
        // closed tab means reading the site and paying for it twice.
        $this->actingAs($operator)
            ->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('draft.id', $project->getKey()));
    }

    /**
     * The engine looks at the site instead of only reading it.
     *
     * Site analysis has always parsed text, so "what does this brand look like"
     * was a model's guess from the copy and the Brand Brief's colours had to be
     * typed in by hand. A picture answers it by counting.
     */
    #[Test]
    public function analysing_a_site_reads_its_colours_off_a_picture_of_it(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        Http::fake(['*/screenshot' => Http::response(
            $this->pagePng(),
            200,
            ['Content-Type' => 'image/png'],
        )]);

        // A host that resolves, because the guard this goes through does real
        // DNS and refuses what it cannot resolve. The fake reader ignores the
        // address, so the site being read is still Cleaning Point's.
        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://example.com'])
            ->assertOk();

        $palette = Project::query()->firstOrFail()->site_analysis['palette'];

        // The header's green, not the page's white — the most common colour on
        // a marketing site is the one thing that is certainly not the brand's.
        $this->assertNotNull($palette);
        $this->assertSame('#204040', $palette['fill']);

        // The renderer is handed the addresses the guard already resolved, so
        // Chromium cannot do its own DNS from inside the compose network.
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/screenshot')
            && $request['host'] === 'example.com'
            && $request['addresses'] !== []);
    }

    /**
     * A host that cannot be pinned is not photographed.
     *
     * `allow_unresolved_hosts` lets a plain fetch through on trust, and cURL
     * simply fails it. A browser is a different proposition: without addresses
     * there is nothing to pin Chromium to, and an unpinned browser inside the
     * compose network resolves `postgres` and `horizon` as happily as anything
     * else. So there is no request at all — not a request that fails.
     */
    #[Test]
    public function a_host_with_no_resolved_address_is_never_handed_to_the_browser(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();
        config([
            'content_studio.renderer.url' => 'http://renderer:3020',
            'security.outbound.allow_unresolved_hosts' => true,
        ]);

        Http::fake(['*/screenshot' => Http::response($this->pagePng(), 200)]);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://nothing.invalid'])
            ->assertOk();

        $this->assertNull(Project::query()->firstOrFail()->site_analysis['palette']);
        Http::assertNothingSent();
    }

    /**
     * A missing browser costs the colours and nothing else.
     *
     * The capability is optional in the same way the panel renderer is: a
     * deployment without it analyses sites exactly as it did before. Making the
     * whole analysis conditional on an optional service would be the tail
     * wagging the dog.
     */
    #[Test]
    public function a_deployment_with_no_renderer_still_analyses_the_site(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();
        config(['content_studio.renderer.url' => null]);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk()
            ->assertJsonPath('project.name', 'Cleaning Point');

        $this->assertNull(Project::query()->firstOrFail()->site_analysis['palette']);
    }

    /**
     * A renderer that will not answer is not a failed onboarding.
     *
     * Every reason the picture can fail — the service down, the site refusing a
     * browser, a page too slow — is a reason to have no suggestion, never a
     * reason to lose an interpretation of the copy that already succeeded and
     * was already paid for.
     */
    #[Test]
    public function a_screenshot_that_fails_costs_the_suggestion_and_not_the_analysis(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();
        config(['content_studio.renderer.url' => 'http://renderer:3020']);

        Http::fake(['*/screenshot' => Http::response(['message' => 'net::ERR_ABORTED'], 502)]);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk()
            ->assertJsonPath('project.name', 'Cleaning Point');

        $this->assertNull(Project::query()->firstOrFail()->site_analysis['palette']);
    }

    /**
     * The brief offers the colours; it never applies them.
     *
     * A wrong fill is not a visible error — it silently becomes every carousel
     * for a month — so this is the same shape as the month's goal: the engine
     * proposes and a person agrees.
     */
    #[Test]
    public function the_brief_is_offered_the_sites_colours_rather_than_given_them(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create([
            'site_analysis' => ['palette' => [
                'fill' => '#2f4f43',
                'ink' => '#f3efe6',
                'accent' => '#d6533c',
            ]],
        ]);
        $project->users()->attach($operator, ['role' => 'owner']);

        $this->actingAs($operator)
            ->withSession(['project_id' => $project->getKey()])
            ->get('/brief')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('palette.fill', '#2f4f43')
                ->where('palette.accent', '#d6533c')
                ->etc()
            );

        app(CurrentProject::class)->run($project, function (): void {
            // Nothing was written. The brief keeps the house values until
            // somebody clicks the suggestion.
            $brief = BrandBrief::activeFor(app(CurrentProject::class)->get());

            $this->assertNotSame('#2f4f43', $brief?->brand_colour);
        });
    }

    #[Test]
    public function analysing_again_reuses_the_draft_instead_of_piling_up_projects(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel(2);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk();

        $project = Project::query()->firstOrFail();

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', [
                'url' => 'https://cleaningpoint.pt',
                'project_id' => $project->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('project.id', $project->getKey());

        $this->assertSame(1, Project::query()->count());
    }

    #[Test]
    public function a_site_that_will_not_load_says_so_rather_than_inventing_a_business(): void
    {
        $operator = User::factory()->create();

        $this->app->instance(
            SiteReader::class,
            (new FakeSiteReader)->willFail('example.com did not respond.'),
        );

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://example.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'example.com did not respond.');

        $this->assertSame(0, Project::query()->count());
    }

    #[Test]
    public function finishing_the_wizard_writes_a_brief_connects_channels_and_starts_research(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $this->fakeModel();

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk();

        $project = Project::query()->firstOrFail();
        $id = $project->getKey();

        $this->actingAs($operator)->postJson("/onboarding/{$id}/save", [
            'step' => 'market',
            'answers' => ['market' => 'pt', 'language' => 'pt-PT'],
        ])->assertOk();

        $this->actingAs($operator)->postJson("/onboarding/{$id}/save", [
            'step' => 'business',
            'answers' => [
                'name' => 'Cleaning Point',
                'description' => 'We clean flats in Lisbon.',
                'audiences' => ['Lisbon flat owners'],
            ],
        ])->assertOk();

        $this->actingAs($operator)->postJson("/onboarding/{$id}/save", [
            'step' => 'voice',
            'answers' => [
                'tone' => 'Plain and practical.',
                'forbidden' => ['medical claims'],
                'author_name' => 'Ana Reis',
                'author_title' => 'Operations',
                'example_liked' => 'We arrive at 9 and are usually gone by 11.',
                'example_disliked' => 'Unlock the secret to a spotless home!',
            ],
        ])->assertOk();

        $this->actingAs($operator)->postJson("/onboarding/{$id}/save", [
            'step' => 'channels',
            'answers' => [
                'webhook_endpoint' => 'https://cleaningpoint.pt/api/content',
                'social' => ['linkedin', 'x'],
            ],
        ])->assertOk();

        $this->actingAs($operator)->postJson("/onboarding/{$id}/save", [
            'step' => 'settings',
            'answers' => ['weekly_target' => 5, 'autopublish' => true],
        ])->assertOk();

        $this->actingAs($operator)
            ->post("/onboarding/{$id}/launch")
            ->assertRedirect('/home');

        $project->refresh();

        $this->assertSame(OnboardingStatus::Launching, $project->onboarding_status);
        $this->assertSame(5, $project->weekly_target);
        $this->assertTrue($project->autopublish);
        $this->assertSame([['name' => 'Ana Reis', 'title' => 'Operations']], $project->authors);

        // Whoever finished the wizard is now working in what they just made.
        $this->assertSame($id, session(ProjectManager::SESSION_KEY));

        app(CurrentProject::class)->run($project, function () use ($project): void {
            $brief = BrandBrief::activeFor($project);

            $this->assertNotNull($brief);
            $this->assertSame('We clean flats in Lisbon.', $brief->positioning);
            $this->assertSame('Plain and practical.', $brief->tone);
            $this->assertContains('medical claims', $brief->forbidden_topics);
            $this->assertSame(
                ['We arrive at 9 and are usually gone by 11.'],
                $brief->examples_liked,
            );

            // §8: one content layer. The site and the social channels come out
            // of the same wizard and read the same brief.
            $this->assertSame(
                ['LinkedIn', 'Website', 'X'],
                Channel::query()->pluck('name')->sort()->values()->all(),
            );
            $this->assertSame(
                ChannelType::Webhook,
                Channel::query()->where('name', 'Website')->firstOrFail()->type,
            );
        });

        // The engine started itself. Nobody has to press a second button.
        Queue::assertPushed(
            RunStepJob::class,
            static fn (RunStepJob $job): bool => $job->stepKey === 'apply_content_studio_action'
                && $job->queue === 'pipeline-expensive',
        );

        $runs = PipelineRun::acrossProjects()
            ->where('project_id', $id)
            ->orderBy('pipeline')
            ->get();

        // Three independent contours, not a chain: the Studio proposal, the SEO
        // research the month is planned from, and a reading of the website the
        // operator has just handed over. None needs the others, so one provider
        // failing cannot silently cancel the rest.
        $this->assertSame(
            ['content_studio', 'research', 'site_audit'],
            $runs->pluck('pipeline')->all(),
        );
        $this->assertTrue($runs->every(
            static fn (PipelineRun $run): bool => $run->status !== PipelineRunStatus::Failed,
        ));

        app(CurrentProject::class)->run($project, function (): void {
            $plan = ContentPlan::query()->firstOrFail();
            $run = PipelineRun::query()->where('pipeline', 'content_studio')->firstOrFail();

            $this->assertSame($plan->getKey(), $run->input['content_plan_id']);
            $this->assertSame('proposal', $run->input['action']);
        });
    }

    #[Test]
    public function a_ymyl_project_cannot_be_set_to_publish_without_review(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create(['is_ymyl' => true]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'settings',
            'answers' => ['weekly_target' => 3, 'autopublish' => true],
        ])->assertOk();

        // The checkbox is disabled in the wizard, but a disabled checkbox is a
        // suggestion — money and health content is reviewed whatever was sent.
        $this->assertFalse($project->refresh()->autopublish);
    }

    #[Test]
    public function launching_starts_the_free_window_and_the_engine_can_use_it(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        // Unbilled, because this is the state a project is really in when the
        // wizard's last step is pressed — the trial does not exist yet.
        $project = Project::factory()->onboarding()->unbilled()->create([
            'site_analysis' => ['description' => 'A Lisbon cleaning business.'],
        ]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect('/home');

        $subscription = ProjectSubscription::query()->sole();

        $this->assertSame('trial', $subscription->plan);
        $this->assertSame(BillingStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->trial_ends_at?->isFuture());
        $this->assertSame($operator->getKey(), $subscription->billing_user_id);

        // The clock starts when the engine does, not when the account was made.
        $this->assertTrue($subscription->trial_ends_at->isSameDay(
            now()->addDays((int) config('billing.trial.days')),
        ));

        // And the first run of the project's life was not gated out: the trial
        // is minted inside the same transaction, before research begins.
        $this->assertSame(1, PipelineRun::acrossProjects()->where('pipeline', 'research')->count());
    }

    #[Test]
    public function launching_twice_does_not_mint_a_second_trial(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->unbilled()->create([
            'site_analysis' => ['description' => 'A Lisbon cleaning business.'],
        ]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->post("/onboarding/{$project->getKey()}/launch");
        $this->actingAs($operator)->post("/onboarding/{$project->getKey()}/launch");

        $this->assertSame(1, ProjectSubscription::query()->count());
    }

    #[Test]
    public function launching_twice_does_not_start_a_second_month(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create([
            'site_analysis' => ['description' => 'A Lisbon cleaning business.'],
        ]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect('/home');

        $this->actingAs($operator)->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect('/home');

        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'research')->count(),
            'A double-submitted last step must not research and plan the month twice.',
        );
        $this->assertSame(
            1,
            PipelineRun::acrossProjects()->where('pipeline', 'content_studio')->count(),
            'A double-submitted last step must not propose the social month twice.',
        );
    }

    #[Test]
    public function a_wizard_nobody_answered_will_not_launch(): void
    {
        Queue::fake();

        $operator = User::factory()->create();
        // No analysis and no answers: a brief compiled from this would have no
        // positioning, and every article for a month would come out of it.
        $project = Project::factory()->onboarding()->create(['site_analysis' => []]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)
            ->from('/onboarding')
            ->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect('/onboarding');

        $this->assertSame(OnboardingStatus::Draft, $project->refresh()->onboarding_status);
        $this->assertSame(0, PipelineRun::acrossProjects()->count());
    }

    #[Test]
    public function the_wizard_cannot_be_used_to_edit_a_launched_project(): void
    {
        $operator = User::factory()->create();
        // Live, not a draft. Answering a wizard step against it would rewrite
        // its name, locale, cadence and autopublish flag without going near the
        // validation the settings form applies.
        $project = Project::factory()->create(['weekly_target' => 3]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'settings',
            'answers' => ['weekly_target' => 50, 'autopublish' => true],
        ])->assertNotFound();

        $this->assertSame(3, $project->refresh()->weekly_target);
    }

    #[Test]
    public function the_market_step_answers_when_somebody_is_on_duty(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        // Until it is answered the project is never on duty, so the planner
        // schedules nothing rather than posting into a silence (§4.3).
        $this->assertTrue($project->dutyHours()->isEmpty());

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'market',
            'answers' => [
                'market' => 'pt',
                'language' => 'pt-PT',
                // The zone the hours are read in, answered on the same step
                // for exactly that reason.
                'timezone' => 'Europe/Lisbon',
                'duty_hours' => [
                    'sat' => [['10:00', '12:00']],
                    'mon' => [['12:00', '18:00'], ['9:00', '12:00']],
                ],
            ],
        ])->assertOk();

        $project->refresh();

        $this->assertSame('Europe/Lisbon', $project->timezone);
        $this->assertSame(
            ['mon' => [['09:00', '18:00']], 'sat' => [['10:00', '12:00']]],
            $project->dutyHours()->toArray(),
        );

        // A later step must not wipe an answer it never asked about.
        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'settings',
            'answers' => ['weekly_target' => 3],
        ])->assertOk();

        $this->assertFalse($project->refresh()->dutyHours()->isEmpty());
    }

    #[Test]
    public function duty_hours_that_are_not_day_keyed_ranges_are_refused_rather_than_stored(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);
        $url = "/onboarding/{$project->getKey()}/save";

        $this->actingAs($operator)->postJson($url, [
            'step' => 'market',
            'answers' => ['language' => 'pt-PT', 'duty_hours' => ['funday' => [['09:00', '18:00']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.duty_hours');

        $this->actingAs($operator)->postJson($url, [
            'step' => 'market',
            'answers' => ['language' => 'pt-PT', 'duty_hours' => ['mon' => 'all day']],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.duty_hours.mon');

        $project->refresh();

        $this->assertSame([], $project->onboarding);
        $this->assertNull($project->duty_hours);
    }

    #[Test]
    public function an_unknown_step_is_refused_rather_than_stored(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'whatever',
            'answers' => ['anything' => 'at all'],
        ])->assertStatus(422);

        $this->assertSame([], $project->refresh()->onboarding);
    }

    #[Test]
    public function every_wizard_step_validates_its_shape_and_bounds(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);
        $url = "/onboarding/{$project->getKey()}/save";

        $this->actingAs($operator)->postJson($url, [
            'step' => 'business',
            'answers' => ['name' => 'Business', 'description' => 'Description', 'audiences' => 'everyone'],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.audiences');

        $this->actingAs($operator)->postJson($url, [
            'step' => 'settings',
            'answers' => ['weekly_target' => 99, 'target_words' => 100],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'answers.weekly_target',
            'answers.target_words',
        ]);

        $this->actingAs($operator)->postJson($url, [
            'step' => 'market',
            'answers' => ['language' => 'not a locale', 'unexpected' => 'stored forever'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['answers', 'answers.language']);

        $this->assertSame([], $project->refresh()->onboarding);
    }

    #[Test]
    public function wizard_urls_must_resolve_to_public_network_targets(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);
        $url = "/onboarding/{$project->getKey()}/save";

        $this->actingAs($operator)->postJson($url, [
            'step' => 'voice',
            'answers' => ['sitemap_url' => 'http://127.0.0.1/private.xml'],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.sitemap_url');

        $this->actingAs($operator)->postJson($url, [
            'step' => 'channels',
            'answers' => ['webhook_endpoint' => 'http://169.254.169.254/latest/meta-data'],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.webhook_endpoint');
    }

    #[Test]
    public function a_half_finished_draft_never_becomes_the_project_the_panel_is_about(): void
    {
        $operator = User::factory()->create();
        $this->fakeModel();

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://cleaningpoint.pt'])
            ->assertOk();

        // Scoping the panel to a draft would show a dashboard for a project
        // with no brief, no plan and nothing running — and put it in the
        // switcher looking exactly like a real one.
        $this->actingAs($operator)
            ->get('/home')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('project', null)
                ->where('auth.projects', [])
            );

        $draft = Project::query()->firstOrFail();

        $this->actingAs($operator)
            ->post("/projects/{$draft->getKey()}/switch")
            ->assertForbidden();
    }

    #[Test]
    public function another_operators_draft_cannot_be_edited(): void
    {
        $operator = User::factory()->create();
        $operator->projects()->attach(Project::factory()->create());

        $theirs = Project::factory()->onboarding()->create();

        $this->actingAs($operator)->postJson("/onboarding/{$theirs->getKey()}/save", [
            'step' => 'market',
            'answers' => ['market' => 'de'],
        ])->assertNotFound();

        $this->actingAs($operator)
            ->post("/onboarding/{$theirs->getKey()}/launch")
            ->assertNotFound();
    }

    private function fakeModel(int $times = 1): void
    {
        $gateway = new FakeModelGateway;
        $gateway->willAnswer(array_fill(0, $times, self::ANSWER));

        $this->app->instance(ModelGateway::class, $gateway);
    }

    /**
     * A marketing page as PNG bytes: white, with a green band across the top.
     *
     * Generated rather than a captured fixture, so the test states the shape it
     * is asserting about instead of depending on what one real site looked like
     * on one day.
     */
    private function pagePng(): string
    {
        $image = imagecreatetruecolor(320, 240);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 47, 79, 67);

        if ($white === false || $green === false) {
            throw new RuntimeException('The test image ran out of colours.');
        }

        imagefilledrectangle($image, 0, 0, 320, 240, $white);
        imagefilledrectangle($image, 0, 0, 320, 70, $green);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
