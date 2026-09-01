<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\ChannelType;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\ProjectManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * A bucket that is down is not the operator's fault, and must not be described
 * as though it were.
 *
 * `uploadImage` catches RuntimeException and answers 422 — which is right for
 * the things that genuinely are the picture's fault, and wrong for a storage
 * failure, which inherits from RuntimeException on its way through
 * RetryableStepFailure. Left there, an outage told everybody their photograph
 * was unacceptable, and told nobody else anything: every disk is configured
 * `report => false`, so nothing was logged either.
 */
final class UploadStorageFailureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_refused_upload_is_a_server_failure_and_not_a_rejected_picture(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create();
        $operator->projects()->attach($project, ['role' => 'owner']);

        $plan = ContentPlan::factory()->create(['project_id' => $project->getKey()]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'content_plan_id' => $plan->getKey(),
            'channel_type' => ChannelType::Instagram->value,
        ]);

        config(['media.disk' => 'refusing']);
        $refusing = $this->createMock(Filesystem::class);
        $refusing->method('put')->willReturn(false);
        Storage::set('refusing', $refusing);

        $response = $this->actingAs($operator)
            ->withSession([ProjectManager::SESSION_KEY => $project->getKey()])
            ->postJson("/studio/drafts/{$item->getKey()}/photo", [
                'photo' => UploadedFile::fake()->image('holiday.jpg', 1200, 1200),
            ]);

        // 503 and not 422: the picture was fine.
        $response->assertStatus(503);
        $this->assertStringNotContainsString('disk', (string) $response->json('message'));
    }
}
