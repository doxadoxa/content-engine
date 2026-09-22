<?php

declare(strict_types=1);

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Models\Channel;
use App\Models\PageOpportunity;
use App\Models\PagePublicationOperation;
use App\Models\Project;
use App\Models\User;
use App\Pages\BusinessFacts;
use App\Pages\TrackedPages;
use App\Proposals\PageBlocks;
use App\Proposals\Proposals;
use App\Publishing\Pages\DispatchPageOperation;
use App\Publishing\Pages\EditablePages;
use App\Publishing\Pages\NativePublication;
use App\Publishing\Pages\NativePublicVerification;
use App\Publishing\Pages\PageOperationDispatcher;
use App\Support\Http\PublicHttpTarget;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('This acceptance fixture runs only from a local console.');
}
$fixture = $argv[1] ?? '';
if (! in_array($fixture, ['wordpress', 'cleaningpoint'], true)) {
    throw new RuntimeException('Select wordpress or cleaningpoint. Supply its private connection JSON on stdin.');
}
$connection = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$origin = $fixture === 'wordpress' ? 'http://localhost:8093' : 'http://localhost:8094';
$app->instance(PublicHttpTarget::class, PublicHttpTarget::forLocalFixture($fixture));
$models = new FakeModelGateway;
$app->instance(ModelGateway::class, $models);
config(['queue.default' => 'sync']);
Queue::fake([DispatchPageOperation::class]);

if (isset($argv[2])) {
    $operation = PagePublicationOperation::withoutGlobalScopes()->findOrFail($argv[2]);
    $project = Project::query()->findOrFail($operation->project_id);
    checkFixture(str_starts_with($project->name, 'Synthetic native acceptance') && $project->website_url === $origin, 'Only this named synthetic fixture may be recovered.');
    $app->make(CurrentProject::class)->set($project);
    $operation = PagePublicationOperation::query()->findOrFail($operation->id);
    $owner = User::query()->where('email', 'admin@content-engine.test')->firstOrFail();
    $recovery = $app->make(NativePublication::class)->recover($operation->publication, $owner);
    $app->make(PageOperationDispatcher::class)->attempt($recovery);
    checkFixture($recovery->refresh()->committed_at !== null, 'Fixture recovery was not confirmed.', ['status' => $recovery->status, 'error' => $recovery->last_error]);
    $app->make(NativePublicVerification::class)->verify($recovery->publication, $owner);
    checkFixture($recovery->refresh()->status === 'verified', 'Fixture public recovery failed.', $recovery->publication->checks()->latest()->first()?->results);
    echo 'PASS: isolated failed-operation recovery verified; original evidence retained.'.PHP_EOL;
    exit(0);
}

function checkFixture(bool $condition, string $message, mixed $details = null): void
{
    if (! $condition) {
        throw new RuntimeException($message.($details === null ? '' : ': '.json_encode($details, JSON_THROW_ON_ERROR)));
    }
}

// Paid model answers are deliberately scripted. All public/CMS HTTP, signing,
// persistence, compilation, approval, reconciliation and verification are real.
// Keep this isolated project paused so normal scheduled work cannot start on it.
$project = Project::factory()->paused()->create([
    'name' => 'Synthetic native acceptance — '.$fixture,
    'website_url' => $origin, 'market' => 'pt',
]);
$owner = User::query()->where('email', 'admin@content-engine.test')->firstOrFail();
$owner->projects()->attach($project, ['role' => 'owner']);
$app->make(CurrentProject::class)->set($project);
$channel = Channel::query()->create([
    'name' => 'Isolated '.$fixture.' receiver', 'type' => $fixture === 'wordpress' ? 'wordpress' : 'webhook',
    'config' => $fixture === 'wordpress'
        ? ['page_receiver_base' => $origin.'/wp-json/avyo/v1', 'username' => $connection['editor']['username']]
        : ['page_receiver_base' => $origin.'/api/avyo/pages/v1'],
    'secret' => $fixture === 'wordpress' ? $connection['editor']['password'] : $connection['secret'],
    'is_enabled' => true,
]);
echo json_encode(['fixture' => $fixture, 'synthetic_project_id' => $project->id], JSON_THROW_ON_ERROR).PHP_EOL;

foreach (['service', 'article'] as $object) {
    $url = $connection['urls'][$object];
    checkFixture(str_starts_with($url, $origin.'/'), 'Fixture URL must use its named local origin.');
    $objectType = $fixture === 'wordpress' ? ($object === 'service' ? 'page' : 'post') : $object;
    $page = $app->make(TrackedPages::class)->track($project, $url, 'en', $object === 'service' ? 'commercial' : 'editorial');
    $editable = $app->make(EditablePages::class)->bind($page, $channel, (string) $connection['objects'][$object], $objectType);
    $initialFields = $editable->fields;

    foreach (['text_section', 'title'] as $kind) {
        $page = $app->make(TrackedPages::class)->capture($project, $page);
        $editable = $app->make(EditablePages::class)->capture($page);
        $snapshot = $page->fresh()->latestSnapshot;
        $blocks = collect($app->make(PageBlocks::class)->from($snapshot));
        $plainBefore = $fixture === 'wordpress'
            ? ($object === 'service' ? 'Home cleaning visits include the kitchen and bathroom.' : 'Prepare your home before the cleaning visit.')
            : ($object === 'service' ? $editable->fields['intro'] : null);
        $block = $plainBefore === null
            ? $blocks->first(fn (array $block): bool => $block['tag'] === 'p' && $block['links'] === [] && str_contains($editable->fields['body_markdown'], $block['text']))
            : $blocks->firstWhere('text', PageBlocks::normalize($plainBefore));
        checkFixture($kind === 'title' || $block !== null, 'The real rendered fixture paragraph was not found.', ['object' => $object]);
        $before = $kind === 'title' ? $snapshot->fields['title'] : $block['text'];
        $after = $kind === 'title'
            ? str_replace($editable->fields['title'], $editable->fields['title'].' reviewed', $before)
            : 'The synthetic fixture includes a reviewed cleaning checklist.';
        $fact = $app->make(BusinessFacts::class)->save($project, $owner, [
            'name' => 'Synthetic '.$object.' '.$kind.' rule', 'statement' => $after,
            'source_url' => $url, 'source_note' => 'Synthetic local integration fixture, not evidence about Cleaning Point.',
            'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(30)->toDateString(),
        ]);
        $opportunity = PageOpportunity::query()->create([
            'site_page_id' => $page->id, 'kind' => 'missing_business_fact', 'diagnosed_issue' => 'Synthetic receiver acceptance',
            'suggested_scope' => 'One reviewed '.$kind.' edit',
            'evidence_snapshot' => ['snapshot_id' => $snapshot->id, 'canonical_url' => $page->canonical_url, 'locale' => 'en'],
            'confidence' => 'low', 'effort' => 'small', 'ranking_factors' => [], 'missing_fact_questions' => [], 'overlap_page_ids' => [],
            'status' => 'open', 'fingerprint' => hash('sha256', (string) Str::uuid()), 'diagnosed_at' => now(),
        ]);
        $models->willAnswer([json_encode(['changes' => [[
            'kind' => $kind, 'operation' => 'replace', 'locator' => $kind === 'title' ? 'title' : $block['id'],
            'before' => $before, 'after' => $after, 'reason' => 'Verify exact approved field publication and recovery.',
            'fact_version_ids' => [$fact->current_version_id], 'target_page_id' => null, 'anchor_text' => null,
        ]], 'missing_facts' => []], JSON_THROW_ON_ERROR)]);
        $proposal = $app->make(Proposals::class)->begin($opportunity, $owner);
        $revision = $proposal->currentRevision;
        checkFixture($revision !== null, 'Proposal generation did not produce a revision.', ['status' => $proposal->status, 'reason' => $proposal->invalidation_reason]);
        checkFixture(($revision->compiled_patch['status'] ?? null) === 'supported', 'Native compilation refused the fixture.', $revision->compiled_patch);
        $app->make(Proposals::class)->accept($proposal, $owner, $revision->id, 0);
        $operation = $app->make(NativePublication::class)->authorize($proposal->fresh(), $owner, $revision->id);
        checkFixture($operation->committed_at === null, 'Authorization alone must not claim a write.');
        $app->make(PageOperationDispatcher::class)->attempt($operation);
        checkFixture($operation->refresh()->status === 'applied_unverified', 'Real receiver write was not confirmed.', ['status' => $operation->status, 'error' => $operation->last_error]);
        $app->make(NativePublicVerification::class)->verify($operation->publication, $owner);
        checkFixture($operation->refresh()->status === 'verified', 'Public HTML did not verify the approved edit.', $operation->publication->checks()->latest()->first()?->results);
        $verifiedAt = $operation->publication->fresh()->verified_at->toIso8601String();
        $appliedRevision = $app->make(EditablePages::class)->capture($page)->revision;
        $app->make(PageOperationDispatcher::class)->attempt($operation, force: true);
        checkFixture($app->make(EditablePages::class)->capture($page)->revision === $appliedRevision, 'Retry changed a confirmed source again.');
        $recovery = $app->make(NativePublication::class)->recover($operation->publication->fresh(), $owner);
        $app->make(PageOperationDispatcher::class)->attempt($recovery);
        checkFixture($recovery->refresh()->committed_at !== null, 'Recovery was not confirmed.', ['status' => $recovery->status, 'error' => $recovery->last_error]);
        $app->make(NativePublicVerification::class)->verify($recovery->publication, $owner);
        checkFixture($recovery->refresh()->status === 'verified', 'Public restoration did not verify.', $recovery->publication->checks()->latest()->first()?->results);
        checkFixture($app->make(EditablePages::class)->capture($page)->fields === $initialFields, 'Recovery did not restore the original source fields.');
        checkFixture($recovery->publication->fresh()->verified_at->toIso8601String() === $verifiedAt, 'Recovery rewrote the original verification history.');
        echo json_encode(['object' => $object, 'kind' => $kind, 'proposal_id' => $proposal->id, 'publication' => 'verified', 'duplicate' => 'unchanged', 'recovery' => 'verified', 'public_url' => $url], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
echo 'PASS: real local HTTP publication, duplicate suppression, public verification and guarded recovery; synthetic project retained for UI review.'.PHP_EOL;
