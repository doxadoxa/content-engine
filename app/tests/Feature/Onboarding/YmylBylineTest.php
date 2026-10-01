<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Http\Requests\ProjectRequest;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who stands behind an article on a money-or-health site, and who decides a
 * site is one.
 *
 * A YMYL project used to finish setup without an author, launch, and then stop
 * at the first step of its first article with a message nobody outside the
 * team could act on. Setup now asks, settings can answer, and the launch fills
 * the gap itself so the sample article is always written.
 */
final class YmylBylineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reading_the_site_keeps_the_reason_for_its_verdict(): void
    {
        $operator = User::factory()->create();
        $this->answer('no — scheduling software for sports clubs, no advice');

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://courtly.cloud'])
            ->assertOk()
            ->assertJsonPath('project.is_ymyl', false)
            ->assertJsonPath('project.analysis.ymyl_reason', 'scheduling software for sports clubs, no advice');
    }

    #[Test]
    public function a_yes_is_still_read_as_a_yes(): void
    {
        $operator = User::factory()->create();
        $this->answer('"yes" — gives investment and tax advice');

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://ledgerwise.example'])
            ->assertOk()
            ->assertJsonPath('project.is_ymyl', true)
            ->assertJsonPath('project.analysis.ymyl_reason', 'gives investment and tax advice');
    }

    #[Test]
    public function a_ymyl_project_must_say_who_signs_its_articles(): void
    {
        [$operator, $project] = $this->draft(ymyl: true);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['tone' => 'Precise.', 'author_name' => '', 'ai_disclosure' => false],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.author_name');

        $this->assertSame([], $project->refresh()->authors);
    }

    #[Test]
    public function a_named_author_answers_it(): void
    {
        [$operator, $project] = $this->draft(ymyl: true);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['author_name' => 'Ana Reis', 'author_title' => 'Tax adviser', 'ai_disclosure' => false],
        ])->assertOk();

        $project->refresh();
        $this->assertSame([['name' => 'Ana Reis', 'title' => 'Tax adviser']], $project->authors);
        $this->assertFalse($project->ai_disclosure);
        $this->assertTrue($project->hasAccountableByline());
    }

    #[Test]
    public function so_does_publishing_as_the_brand_with_the_ai_label(): void
    {
        [$operator, $project] = $this->draft(ymyl: true);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['author_name' => '', 'ai_disclosure' => true],
        ])->assertOk();

        $project->refresh();
        $this->assertSame([], $project->authors);
        $this->assertTrue($project->ai_disclosure);
        $this->assertTrue($project->hasAccountableByline());
    }

    #[Test]
    public function any_other_project_is_not_asked(): void
    {
        [$operator, $project] = $this->draft(ymyl: false);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['tone' => 'Plain.', 'author_name' => ''],
        ])->assertOk();

        // Off unless somebody turns it on in settings.
        $this->assertFalse($project->refresh()->ai_disclosure);
    }

    #[Test]
    public function a_launch_with_no_byline_still_writes_its_first_article(): void
    {
        Queue::fake();

        // A draft that reached the last step without being asked — answered
        // before the question existed.
        [$operator, $project] = $this->draft(ymyl: true, attributes: [
            'site_analysis' => ['description' => 'Tax help for freelancers.'],
        ]);

        $this->actingAs($operator)
            ->post("/onboarding/{$project->getKey()}/launch")
            ->assertRedirect('/home');

        $project->refresh();
        $this->assertTrue($project->ai_disclosure);
        $this->assertTrue($project->hasAccountableByline());
    }

    #[Test]
    public function settings_can_name_an_author_or_turn_on_the_label(): void
    {
        [$owner, $project] = $this->live(ymyl: true);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => 'Ana Reis', 'author_title' => 'Editor', 'ai_disclosure' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame([['name' => 'Ana Reis', 'title' => 'Editor']], $project->refresh()->authors);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => '', 'ai_disclosure' => true])
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame([], $project->authors);
        $this->assertTrue($project->ai_disclosure);
    }

    #[Test]
    public function settings_cannot_take_both_away_from_a_ymyl_project(): void
    {
        [$owner, $project] = $this->live(ymyl: true);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => '', 'ai_disclosure' => false])
            ->assertSessionHasErrors('author_name');

        $this->assertSame('Taras Dovgal', $project->refresh()->authors[0]['name']);
    }

    #[Test]
    public function support_marking_it_sensitive_mid_save_still_keeps_the_byline(): void
    {
        [$owner, $project] = $this->live(ymyl: false);
        $project->forceFill(['authors' => [], 'ai_disclosure' => false])->save();
        $this->markSensitiveOnceValidated($project);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => '', 'ai_disclosure' => false])
            ->assertSessionHasErrors('author_name');

        $project->refresh();
        $this->assertTrue($project->is_ymyl);
        $this->assertTrue($project->ai_disclosure);
    }

    #[Test]
    public function support_marking_it_sensitive_mid_save_still_stops_automatic_publishing(): void
    {
        [$owner, $project] = $this->live(ymyl: false);
        $this->markSensitiveOnceValidated($project);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'autopublish' => true])
            ->assertSessionHasErrors('autopublish');

        $this->assertFalse($project->refresh()->autopublish);
    }

    #[Test]
    public function an_owner_cannot_turn_ymyl_off_themselves(): void
    {
        [$owner, $project] = $this->live(ymyl: true);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'is_ymyl' => false]);

        $this->assertTrue($project->refresh()->is_ymyl);

        $this->actingAs($owner)
            ->post("/admin/projects/{$project->id}/sensitivity", ['is_ymyl' => false])
            ->assertNotFound();

        $this->assertTrue($project->refresh()->is_ymyl);
    }

    #[Test]
    public function support_can_and_it_is_on_the_record(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $project] = $this->live(ymyl: true);

        $this->actingAs($admin)
            ->post("/admin/projects/{$project->id}/sensitivity", ['is_ymyl' => false])
            ->assertRedirect();

        $this->assertFalse($project->refresh()->is_ymyl);

        $action = AdminAction::query()->where('project_id', $project->id)->sole();
        $this->assertSame('project.sensitivity', $action->action);
        $this->assertTrue($action->before['is_ymyl']);
        $this->assertFalse($action->after['is_ymyl']);
    }

    #[Test]
    public function marking_a_project_sensitive_stops_automatic_publishing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $project] = $this->live(ymyl: false);
        $project->forceFill(['autopublish' => true])->save();

        $this->actingAs($admin)
            ->post("/admin/projects/{$project->id}/sensitivity", ['is_ymyl' => true])
            ->assertRedirect();

        $project->refresh();
        $this->assertTrue($project->is_ymyl);
        $this->assertFalse($project->autopublish);
    }

    /** @return array<string, array{string, bool, string}> */
    public static function verdicts(): array
    {
        return [
            'full stop' => ['No.', false, ''],
            'hyphen' => ['no - booking software', false, 'booking software'],
            'en dash' => ['Yes – sells medication online', true, 'sells medication online'],
            'comma' => ['Yes, gives tax advice', true, 'gives tax advice'],
            'brackets' => ['yes (loans)', true, 'loans'],
            'in the site\'s language' => ['Sim — conselhos fiscais', true, 'conselhos fiscais'],
            'in Italian' => ['Sì — consulenza fiscale', true, 'consulenza fiscale'],
            'bold label' => ['**yes** — insurance advice', true, 'insurance advice'],
        ];
    }

    #[Test]
    #[DataProvider('verdicts')]
    public function the_verdict_is_read_however_the_model_phrases_it(string $answer, bool $ymyl, string $reason): void
    {
        $operator = User::factory()->create();
        $this->answer($answer);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://example.com'])
            ->assertOk()
            ->assertJsonPath('project.is_ymyl', $ymyl)
            ->assertJsonPath('project.analysis.ymyl_reason', $reason);
    }

    #[Test]
    public function a_bold_field_label_is_still_read(): void
    {
        $operator = User::factory()->create();
        $gateway = new FakeModelGateway;
        $gateway->willAnswer(["NAME: Ledger\nDESCRIPTION: Tax help.\n**YMYL:** yes — tax advice"]);
        $this->app->instance(ModelGateway::class, $gateway);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://example.com'])
            ->assertOk()
            ->assertJsonPath('project.is_ymyl', true);
    }

    #[Test]
    public function a_missing_verdict_is_a_no(): void
    {
        $operator = User::factory()->create();
        $gateway = new FakeModelGateway;
        $gateway->willAnswer(["NAME: Ledger\nDESCRIPTION: Tax help."]);
        $this->app->instance(ModelGateway::class, $gateway);

        $this->actingAs($operator)
            ->postJson('/onboarding/analyse', ['url' => 'https://example.com'])
            ->assertOk()
            ->assertJsonPath('project.is_ymyl', false);
    }

    #[Test]
    public function a_malformed_author_is_refused_rather_than_crashing(): void
    {
        [$operator, $project] = $this->draft(ymyl: true);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['author_name' => ['Ana'], 'ai_disclosure' => false],
        ])->assertUnprocessable()->assertJsonValidationErrors('answers.author_name');
    }

    #[Test]
    public function a_label_chosen_before_the_site_was_read_again_is_cleared(): void
    {
        // Chosen while the site read as YMYL; read again, it is not, and the
        // wizard no longer asks — so its answer is "off".
        [$operator, $project] = $this->draft(ymyl: false, attributes: ['ai_disclosure' => true]);

        $this->actingAs($operator)->postJson("/onboarding/{$project->getKey()}/save", [
            'step' => 'voice',
            'answers' => ['author_name' => '', 'ai_disclosure' => false],
        ])->assertOk();

        $this->assertFalse($project->refresh()->ai_disclosure);
    }

    #[Test]
    public function a_member_who_is_not_the_owner_cannot_change_the_byline(): void
    {
        [, $project] = $this->live(ymyl: false);
        $member = User::factory()->create();
        $member->projects()->attach($project, ['role' => 'member']);

        $this->actingAs($member)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => 'Someone Else', 'ai_disclosure' => true])
            ->assertForbidden();

        // Project settings are the owner's, byline included.
        $this->assertSame('Operator', $project->refresh()->authors[0]['name']);
        $this->assertFalse($project->ai_disclosure);
    }

    #[Test]
    public function a_blank_author_on_an_ordinary_project_publishes_under_the_brand(): void
    {
        [$owner, $project] = $this->live(ymyl: false);

        $this->actingAs($owner)->withSession(['current_project_id' => $project->id])
            ->patch("/projects/{$project->id}", [...$this->settings($project), 'author_name' => '', 'ai_disclosure' => false])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $project->refresh()->authors);
    }

    #[Test]
    public function support_marking_a_project_sensitive_keeps_it_writing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $project = Project::factory()->create(['authors' => [], 'ai_disclosure' => false]);

        $this->actingAs($admin)
            ->post("/admin/projects/{$project->id}/sensitivity", ['is_ymyl' => true])
            ->assertRedirect();

        $project->refresh();
        $this->assertTrue($project->is_ymyl);
        // Nobody signs its articles, so the brand does, openly.
        $this->assertTrue($project->ai_disclosure);
        $this->assertTrue($project->hasAccountableByline());
    }

    #[Test]
    public function projects_already_stuck_are_given_the_label_by_the_migration(): void
    {
        $stuck = Project::factory()->create(['is_ymyl' => true, 'authors' => []]);
        $blank = Project::factory()->create(['is_ymyl' => true, 'authors' => [['name' => '  ', 'title' => '']]]);
        $signed = Project::factory()->ymyl()->create();
        $ordinary = Project::factory()->create(['authors' => []]);

        $migration = require database_path('migrations/2026_10_01_120000_add_ai_disclosure_to_projects.php');
        $migration->down();
        $migration->up();

        $this->assertTrue($stuck->refresh()->ai_disclosure);
        $this->assertTrue($blank->refresh()->ai_disclosure);
        $this->assertFalse($signed->refresh()->ai_disclosure);
        $this->assertFalse($ordinary->refresh()->ai_disclosure);
    }

    private function answer(string $ymyl): void
    {
        $gateway = new FakeModelGateway;
        $gateway->willAnswer([implode("\n", [
            'NAME: Courtly',
            'DESCRIPTION: Booking software for clubs.',
            'AUDIENCES: club managers',
            'TONE: Friendly.',
            'VISUAL: Courts.',
            'COMPETITORS: playtomic.io',
            'KEYWORDS: padel booking',
            'FORBIDDEN: ',
            'LANGUAGE: en',
            'MARKET: gb',
            "YMYL: {$ymyl}",
        ])]);

        $this->app->instance(ModelGateway::class, $gateway);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Project}
     */
    private function draft(bool $ymyl, array $attributes = []): array
    {
        $operator = User::factory()->create();
        $project = Project::factory()->onboarding()->unbilled()->create([
            'is_ymyl' => $ymyl,
            'authors' => [],
            'autopublish' => false,
            ...$attributes,
        ]);
        $operator->projects()->attach($project, ['role' => 'owner']);

        return [$operator, $project];
    }

    /** @return array{User, Project} */
    private function live(bool $ymyl): array
    {
        $owner = User::factory()->create();
        $project = ($ymyl ? Project::factory()->ymyl() : Project::factory())->create();
        $owner->projects()->attach($project, ['role' => 'owner']);

        return [$owner, $project];
    }

    /**
     * What support's sensitivity action does, landing between the settings
     * request passing validation and the controller taking its lock.
     */
    private function markSensitiveOnceValidated(Project $project): void
    {
        $this->app->afterResolving(ProjectRequest::class, function () use ($project): void {
            Project::query()->whereKey($project->id)->update([
                'is_ymyl' => true,
                'autopublish' => false,
                'ai_disclosure' => true,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function settings(Project $project): array
    {
        return ['name' => $project->name, 'slug' => $project->slug, 'timezone' => $project->timezone,
            'default_locale' => 'en', 'locales' => ['en'], 'status' => 'active'];
    }
}
