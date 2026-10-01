<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Models\AdminAction;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    /** @return array<string, mixed> */
    private function settings(Project $project): array
    {
        return ['name' => $project->name, 'slug' => $project->slug, 'timezone' => $project->timezone,
            'default_locale' => 'en', 'locales' => ['en'], 'status' => 'active'];
    }
}
