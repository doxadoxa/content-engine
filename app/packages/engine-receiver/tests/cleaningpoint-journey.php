<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\SitePage;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleApproval;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\WebhookPublisher;
use App\Publishing\WebhookSignature;
use App\Support\Content\SafeMarkdown;
use App\Support\Http\PublicHttpTarget;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || ! $app->runningInConsole()) {
    throw new RuntimeException('This journey is restricted to the isolated local fixture.');
}
$credentialsPath = $argv[1] ?? '';
$credentials = json_decode(file_get_contents($credentialsPath), true, flags: JSON_THROW_ON_ERROR);
Queue::fake();
$targets = PublicHttpTarget::forLocalFixture('cleaningpoint');
$app->instance(PublicHttpTarget::class, $targets);
if (($credentials['endpoint'] ?? null) !== 'http://localhost:8094/webhooks/avyo' || ($credentials['project_slug'] ?? null) !== 'synthetic-cp-article-calendar') {
    throw new RuntimeException('Only the dedicated synthetic CleaningPoint fixture is allowed.');
}
$project = Project::query()->where('slug', $credentials['project_slug'])->first();
if ($project !== null && ($project->onboarding['local_article_fixture'] ?? false) !== true) {
    throw new RuntimeException('An unrelated project already uses the fixture slug.');
}
$project ??= Project::factory()->create(['name' => '[Synthetic] CleaningPoint article calendar', 'slug' => $credentials['project_slug'], 'website_url' => 'http://localhost:8094', 'timezone' => 'UTC', 'status' => ProjectStatus::Paused, 'onboarding' => ['local_article_fixture' => true]]);
app(CurrentProject::class)->set($project);
$ensure = static function (bool $passed, string $message): void {
    if (! $passed) {
        throw new RuntimeException($message);
    }
};
try {
    $project->forceFill(['status' => ProjectStatus::Active, 'autopublish' => true, 'onboarding' => [...$project->onboarding, 'article_automation_started_at' => now()->subSecond()->toIso8601String()]])->save();
    ProjectSubscription::query()->where('project_id', $project->id)->update(['plan' => 'local-search', 'plan_version' => 3]);
    $channel = Channel::query()->create(['name' => 'Local CleaningPoint article fixture '.Str::lower(Str::random(8)), 'type' => ChannelType::Webhook, 'config' => ['endpoint' => $credentials['endpoint']], 'secret' => $credentials['secret'], 'autopublish' => true, 'is_enabled' => true]);
    $publisher = app(WebhookPublisher::class);
    $ping = $publisher->attempt($publisher->ping($channel, $project));
    $ensure($ping->status === DeliveryStatus::Delivered, 'CleaningPoint connection test failed: '.$ping->error);
    $channel->refresh();
    $body = "## Prepare the rooms\n\nClear a path to the areas being cleaned. Put small personal items away.\n\n## Keep instructions nearby\n\nList any surfaces that need special care. Read the product label before cleaning them.\n\n## Limitations\n\nThis guide does not cover restoration of damaged surfaces. Ask the manufacturer about delicate materials. Test a small hidden area before using a new cleaner.";
    $item = ContentItem::factory()->draft()->create(['title' => 'Preparing your home for a cleaning visit', 'slug' => 'synthetic-cp-scheduled-article-'.Str::lower(Str::random(8)), 'body_markdown' => $body, 'body_html' => app(SafeMarkdown::class)->render($body), 'summary' => 'Prepare your rooms and note special surfaces before a cleaning visit.', 'factcheck' => ['passed' => true, 'findings' => [], 'required' => false], 'public_url' => null, 'published_at' => null]);
    $due = now()->addMinute()->startOfMinute();
    $schedules = app(ArticleSchedules::class);
    $schedule = $schedules->scheduleNew($item, $due, $channel);
    $ensure($schedule !== null && $schedule->status === 'active', 'No active schedule was recorded.');
    $ensure($schedules->dispatch($item) === [], 'An article was dispatched before its publication time.');
    Carbon::setTestNow($due);
    $deliveries = $schedules->dispatch($item);
    $ensure(count($deliveries) === 1, 'Due article did not queue: '.($schedule->fresh()->blocked_reason ?? 'unknown'));
    $delivery = $publisher->attempt($deliveries[0]);
    $ensure($delivery->status === DeliveryStatus::Delivered, 'Article was not delivered: '.$delivery->error);
    $item->refresh();
    $ensure($item->state === ContentItemState::Published && $schedule->fresh()->status === 'completed', 'Article and calendar did not record publication.');
    $ensure($schedules->dispatch($item) === [], 'A completed schedule dispatched twice.');
    $publisher->replay($delivery);
    $ensure(WebhookDelivery::query()->where('content_item_id', $item->id)->count() === 1, 'A repeated completed delivery made a new identity.');
    $ensure((int) DB::table('article_approval_records')->where('content_item_id', $item->id)->sum('units') === 1, 'The article did not use exactly one approval allowance.');
    $page = SitePage::query()->where('content_item_id', $item->id)->sole();
    $ensure($page->tracked_at !== null && $page->body === null && $page->read_at === null, 'Measurement enrolment was missing or claimed a fabricated observation.');
    $target = $targets->validate($item->public_url, 'http://localhost:8094');
    $public = Http::timeout(15)->withoutRedirecting()->withOptions($target->httpOptions())->get($target->url);
    $ensure($public->successful() && str_contains($public->body(), $item->title) && str_contains($public->body(), 'Clear a path to the areas being cleaned.'), 'The published article was not visible at its public URL.');
    $raw = json_encode($delivery->payload_snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $target = $targets->validate($credentials['endpoint']);
    $send = static fn () => Http::timeout(15)->withoutRedirecting()->withOptions($target->httpOptions())
        ->withHeaders(['X-Engine-Contract' => '1', 'X-Engine-Delivery' => $delivery->delivery_id,
            'X-Engine-Event' => $delivery->payload_snapshot['event'], 'X-Engine-Timestamp' => (string) now()->timestamp,
            'X-Engine-Signature' => WebhookSignature::compute($credentials['secret'], now()->timestamp, $raw)])
        ->withBody($raw, 'application/json')->post($target->url);
    $firstReceipt = $send();
    $repeatReceipt = $send();
    $ensure($firstReceipt->successful() && $firstReceipt->json() === $repeatReceipt->json(), 'The actual receiver did not preserve the immutable receipt across retries.');
    $originalUrl = $item->public_url;
    Carbon::setTestNow($due->copy()->addSeconds(2));
    $item->startRefresh();
    $item->markDrafted();
    $updatedBody = $body."\n\n<script>window.__avyoUntrusted=1</script>";
    $item->forceFill(['body_markdown' => $updatedBody, 'body_html' => app(SafeMarkdown::class)->render($updatedBody),
        'json_ld' => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => '</script><script>window.__avyoUntrusted=1</script>'],
        'factcheck' => ['passed' => true, 'findings' => [], 'required' => false]])->save();
    app(ArticleApproval::class)->approve($item);
    $updated = $publisher->attempt($publisher->queue($item, $channel));
    $ensure($updated->status === DeliveryStatus::Delivered && $item->refresh()->public_url === $originalUrl, 'The updated article did not preserve its original website identity.');
    $public = Http::timeout(15)->withoutRedirecting()->withOptions($targets->validate($originalUrl)->httpOptions())->get($originalUrl);
    $ensure($public->successful() && str_contains($public->body(), '\\u003C/script\\u003E'), 'Public JSON-LD was not safely escaped.');
    $dom = new DOMDocument;
    @$dom->loadHTML($public->body());
    foreach ($dom->getElementsByTagName('script') as $script) {
        if ($script->getAttribute('type') !== 'application/ld+json') {
            $ensure(! str_contains($script->textContent, 'window.__avyoUntrusted'), 'Untrusted article/schema text escaped into an executable script.');
        }
    }
    $ensure((int) DB::table('article_approval_records')->where('content_item_id', $item->id)->sum('units') === 1, 'An article update charged another first approval.');
    echo json_encode(['status' => 'passed', 'project' => $project->slug, 'content_id' => $item->id, 'public_url' => $item->public_url, 'schedule_status' => $schedule->fresh()->status, 'article_allowance_units' => 1, 'article_deliveries' => 2, 'receiver_retry_receipt_stable' => true, 'update_preserved_url' => true, 'public_script_escape_checked' => true, 'tracked_page_id' => $page->id, 'synthetic_content' => true, 'paid_model_calls' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    Carbon::setTestNow();
    $project->forceFill(['status' => ProjectStatus::Paused, 'autopublish' => false])->save();
}
