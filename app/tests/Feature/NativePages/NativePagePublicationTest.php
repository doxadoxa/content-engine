<?php

declare(strict_types=1);

namespace Tests\Feature\NativePages;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Models\BusinessFact;
use App\Models\Channel;
use App\Models\PageOpportunity;
use App\Models\PageProposal;
use App\Models\PagePublicationOperation;
use App\Models\Project;
use App\Models\SitePage;
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
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class NativePagePublicationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Channel $channel;

    private SitePage $page;

    private BusinessFact $fact;

    private FakeModelGateway $models;

    /** @var array<string,string> */
    private array $cms = ['title' => 'Cleaning', 'description' => 'Home cleaning', 'body_html' => '<!-- wp:paragraph -->\n<p>Our cleaning service.</p>\n<!-- /wp:paragraph --><form action="/book"><input name="email"><button>Book</button></form>'];

    private int $version = 1;

    /** @var array<string,array<string,mixed>> */
    private array $receipts = [];

    private bool $loseResponse = false;

    private bool $rejectCredentials = false;

    private bool $changeDuringRead = false;

    private int $writes = 0;

    private bool $selfTitleCard = false;

    private bool $corruptReceipt = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms['body_html'] = str_replace('\\n', "\n", $this->cms['body_html']);
        $this->project = Project::factory()->create(['website_url' => 'https://example.com']);
        app(CurrentProject::class)->set($this->project);
        $this->owner = User::factory()->create();
        $this->owner->projects()->attach($this->project, ['role' => 'owner']);
        $this->actingAs($this->owner);
        config(['queue.default' => 'sync']);
        Queue::fake([DispatchPageOperation::class]);
        $this->models = new FakeModelGateway;
        $this->app->instance(ModelGateway::class, $this->models);
        $this->channel = Channel::query()->create(['name' => 'WordPress editor', 'type' => 'wordpress', 'config' => ['page_receiver_base' => 'https://example.com/wp-json/avyo/v1', 'username' => 'page-editor'], 'secret' => 'synthetic-application-password', 'is_enabled' => true]);
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (! str_starts_with($path, '/wp-json/')) {
                return Http::response($this->publicHtml(), 200, ['Content-Type' => 'text/html']);
            }
            if ($this->rejectCredentials) {
                return Http::response(['code' => 'rest_forbidden'], 403);
            }
            if ($request->method() === 'GET' && str_contains($path, '/operations/')) {
                $id = basename($path);

                return isset($this->receipts[$id]) ? Http::response($this->receipts[$id]) : Http::response(['code' => 'avyo_not_found'], 404);
            }
            if ($request->method() === 'GET') {
                return Http::response($this->source());
            }
            $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
            $id = $body['operation_id'];
            if (isset($this->receipts[$id])) {
                return Http::response($this->receipts[$id]);
            }
            if ($this->changeDuringRead) {
                $this->version++;
            }
            $before = $this->source();
            if ($body['expected_revision'] !== $before['revision']) {
                return Http::response(['code' => 'avyo_revision_conflict'], 409);
            }
            if (isset($body['restores_operation_id'])) {
                $original = $this->receipts[$body['restores_operation_id']];
                foreach ($original['changed_fields'] as $field) {
                    $this->cms[$field] = $original['before']['fields'][$field];
                }
            } else {
                foreach ($body['patches'] as $patch) {
                    $field = $patch['field'];
                    if (in_array($field, ['title', 'description'], true)) {
                        $this->cms[$field] = $patch['after'];
                    } else {
                        $replacement = match ($patch['operation']) {
                            'insert_after' => $patch['before']."\n\n".$patch['after'],
                            'link' => '<a href="'.$patch['after'].'">'.$patch['before'].'</a>',
                            default => $patch['after'],
                        };
                        $this->cms[$field] = str_replace($patch['before'], $replacement, $this->cms[$field]);
                    }
                }
            }
            $this->writes++;
            $this->version++;
            $receipt = ['schema_v' => 1, 'operation_id' => $id, 'object_id' => '12', 'kind' => isset($body['restores_operation_id']) ? 'recovery' : 'publish', 'status' => 'applied', 'before' => $before, 'after' => $this->source(), 'changed_fields' => array_values(array_unique(array_column($body['patches'] ?? [], 'field'))), 'committed_at' => now()->toIso8601String(), 'public_verification' => 'required'];
            $this->receipts[$id] = $receipt;
            if ($this->loseResponse) {
                $this->loseResponse = false;
                throw new ConnectionException('The synthetic response was lost after commit.');
            }

            return Http::response($this->corruptReceipt ? [...$receipt, 'kind' => 'wrong_operation'] : $receipt);
        });
        $this->page = app(TrackedPages::class)->track($this->project, 'https://example.com/service', 'en', 'commercial');
        app(EditablePages::class)->bind($this->page, $this->channel, '12', 'page');
        $this->page->refresh();
        $this->fact = app(BusinessFacts::class)->save($this->project, $this->owner, ['name' => 'Scope', 'statement' => 'Cleaning includes floors and bathrooms.', 'source_url' => 'https://example.com/service', 'source_note' => 'Owner checked the service checklist.', 'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(30)->toDateString()]);
    }

    public function test_native_custom_connection_does_not_require_an_unused_article_delivery_endpoint(): void
    {
        $this->post('/channels', ['name' => 'Existing custom pages', 'type' => 'webhook',
            'config' => ['page_receiver_base' => 'https://example.com/api/avyo/pages/v1'],
            'secret' => 'synthetic-page-secret', 'is_enabled' => true])->assertSessionHasNoErrors()->assertRedirect();
        $channel = Channel::query()->where('name', 'Existing custom pages')->firstOrFail();
        $this->assertSame('https://example.com/api/avyo/pages/v1', $channel->config['page_receiver_base']);
        $this->assertArrayNotHasKey('endpoint', $channel->config);
        $this->post('/channels', ['name' => 'Legacy webhook without endpoint', 'type' => 'webhook', 'config' => [], 'secret' => 'synthetic'])->assertSessionHasErrors('config.endpoint');
    }

    public function test_native_revision_pins_editable_source_and_publishes_only_after_explicit_permission(): void
    {
        $proposal = $this->proposal();
        $revision = $proposal->currentRevision;
        $this->assertNotNull($revision->editable_snapshot_id);
        $this->assertSame('public', $revision->sourceSnapshot->source_kind);
        $this->assertSame('wordpress', $revision->editableSnapshot->source_kind);
        $this->assertSame('public', $this->page->fresh()->latestSnapshot->source_kind);
        $this->assertSame('supported', $revision->compiled_patch['status'], $revision->compiled_patch['reason'] ?? '');
        $this->assertSame(0, $this->writes);
        $operation = $this->authorize($proposal);
        $this->assertSame(0, $this->writes);
        $this->assertNull($operation->publication->applied_at);
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame(1, $this->writes);
        $this->assertSame('applied_unverified', $operation->refresh()->status);
        $this->assertNull($operation->publication->fresh()->verified_at);
        app(NativePublicVerification::class)->verify($operation->publication, $this->owner);
        $this->assertSame('verified', $operation->refresh()->status, json_encode($operation->verification_results, JSON_THROW_ON_ERROR));
        $this->assertNotNull($operation->publication->fresh()->verified_at);
        $this->assertStringContainsString('<form action="/book">', $this->cms['body_html']);
        app(PageOperationDispatcher::class)->attempt($operation, force: true);
        $this->assertSame(1, $this->writes);
    }

    public function test_lost_response_reconciles_same_identity_without_a_second_write(): void
    {
        $operation = $this->authorize($this->proposal());
        $body = $operation->request_body;
        $this->loseResponse = true;
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame('outcome_unknown', $operation->refresh()->status);
        $this->assertSame(1, $this->writes);
        $originalCommit = $this->receipts[$operation->delivery_id]['committed_at'];
        $this->travel(3)->hours();
        app(PageOperationDispatcher::class)->attempt($operation, force: true);
        $this->assertSame($originalCommit, $operation->publication->fresh()->applied_at->toIso8601String());
        $this->assertSame('applied_unverified', $operation->refresh()->status);
        $this->assertSame($body, $operation->request_body);
        $this->assertSame(1, $this->writes);
        $this->assertSame(['transport', 'reconcile'], $operation->attemptsLog()->pluck('action')->all());
    }

    public function test_external_edit_causes_conflict_not_success_or_duplicate_publication(): void
    {
        $operation = $this->authorize($this->proposal());
        $this->changeDuringRead = true;
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame('conflict', $operation->refresh()->status);
        $this->assertNull($operation->committed_at);
        $this->assertNull($operation->publication->proposal->fresh()->approved_revision_id);
        $this->assertSame(0, $this->writes);
        app(PageOperationDispatcher::class)->attempt($operation, force: true);
        $this->assertSame(0, $this->writes);
    }

    public function test_title_change_pins_and_verifies_the_coupled_heading_and_preserves_the_template(): void
    {
        $proposal = $this->proposal('title');
        $revision = $proposal->currentRevision;
        $this->assertSame('Cleaning homes', $revision->compiled_patch['patches'][0]['after']);
        $this->assertSame('Cleaning homes', $revision->compiled_patch['rendered_changes'][0]['after']);
        $operation = $this->authorize($proposal);
        app(PageOperationDispatcher::class)->attempt($operation);
        app(NativePublicVerification::class)->verify($operation->publication, $this->owner);
        $this->assertSame('verified', $operation->refresh()->status, json_encode($operation->verification_results, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('<title>Cleaning homes – Company</title>', $this->publicHtml());
    }

    public function test_recovery_is_separately_authorized_restores_fields_and_keeps_original_verified_history(): void
    {
        $before = $this->cms;
        $operation = $this->authorize($this->proposal());
        app(PageOperationDispatcher::class)->attempt($operation);
        app(NativePublicVerification::class)->verify($operation->publication, $this->owner);
        $verifiedAt = $operation->publication->fresh()->verified_at->toIso8601String();
        $recovery = app(NativePublication::class)->recover($operation->publication->fresh(), $this->owner);
        $this->assertSame(1, $this->writes);
        app(PageOperationDispatcher::class)->attempt($recovery);
        $this->assertSame($before, $this->cms);
        $this->assertNotNull($recovery->publication->fresh()->recovered_at);
        app(NativePublicVerification::class)->verify($recovery->publication->fresh(), $this->owner);
        $this->assertSame('recovered', $recovery->publication->fresh()->status);
        $this->assertSame($verifiedAt, $recovery->publication->fresh()->verified_at->toIso8601String());
        $this->assertSame(2, $this->writes);
    }

    public function test_revoked_credentials_and_invalidated_approval_never_start_a_write(): void
    {
        $proposal = $this->proposal();
        $operation = $this->authorize($proposal);
        app(Proposals::class)->invalidate($proposal, 'Evidence changed.');
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame('cancelled', $operation->refresh()->status);
        $this->assertNull($operation->dispatch_started_at);
        $this->assertSame(0, $this->writes);
    }

    public function test_other_tenants_and_nonowners_cannot_bind_or_authorize_native_operations(): void
    {
        $proposal = $this->proposal();
        $member = User::factory()->create();
        $member->projects()->attach($this->project, ['role' => 'operator']);
        $this->actingAs($member)->post('/proposals/'.$proposal->id.'/publish-native', ['revision_id' => $proposal->current_revision_id])->assertForbidden();
        $this->post('/pages/'.$this->page->id.'/cms-binding', ['channel_id' => $this->channel->id, 'object_id' => '12', 'object_type' => 'page'])->assertForbidden();
        $other = Project::factory()->create();
        app(CurrentProject::class)->set($other);
        $otherOwner = User::factory()->create();
        $otherOwner->projects()->attach($other, ['role' => 'owner']);
        $this->actingAs($otherOwner)->post('/proposals/'.$proposal->id.'/publish-native', ['revision_id' => $proposal->current_revision_id])->assertNotFound();
        $this->assertSame(0, $this->writes);
    }

    public function test_wordpress_repeated_self_title_is_reviewed_before_writing_and_all_public_occurrences_verify(): void
    {
        $this->selfTitleCard = true;
        $this->page = app(TrackedPages::class)->capture($this->project, $this->page);
        $proposal = $this->proposal('title');
        $this->assertCount(3, $proposal->currentRevision->compiled_patch['rendered_changes']);
        $this->assertCount(1, $proposal->currentRevision->compiled_patch['patches']);
        $operation = $this->authorize($proposal);
        app(PageOperationDispatcher::class)->attempt($operation);
        app(NativePublicVerification::class)->verify($operation->publication, $this->owner);
        $this->assertSame('verified', $operation->refresh()->status, json_encode($operation->verification_results, JSON_THROW_ON_ERROR));
    }

    public function test_insertion_preserves_the_complete_raw_block_and_public_form(): void
    {
        $proposal = $this->proposal();
        $change = $proposal->currentRevision->changes[0];
        $change['operation'] = 'insert_after';
        $proposal = app(Proposals::class)->revise($proposal, $this->owner, $proposal->current_revision_id, ['changes' => [$change], 'missing_facts' => []], 'Add only the confirmed scope.', 0);
        $this->assertSame('supported', $proposal->currentRevision->compiled_patch['status']);
        $operation = $this->authorize($proposal);
        app(PageOperationDispatcher::class)->attempt($operation);
        app(NativePublicVerification::class)->verify($operation->publication, $this->owner);
        $this->assertSame('verified', $operation->refresh()->status, json_encode($operation->verification_results, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('<p>Our cleaning service.</p>', $this->cms['body_html']);
        $this->assertStringContainsString('<form action="/book">', $this->cms['body_html']);
    }

    public function test_revoked_credentials_are_a_refusal_and_never_an_applied_result(): void
    {
        $operation = $this->authorize($this->proposal());
        $this->rejectCredentials = true;
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame('connection_required', $operation->refresh()->status);
        $this->assertNull($operation->committed_at);
        $this->assertSame(0, $this->writes);
    }

    public function test_rotated_same_account_credentials_can_reconcile_but_cannot_authorize_an_old_payload(): void
    {
        $operation = $this->authorize($this->proposal());
        $this->loseResponse = true;
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->channel->update(['secret' => 'rotated-synthetic-password']);
        app(PageOperationDispatcher::class)->attempt($operation, reconcileOnly: true, force: true);
        $this->assertSame('applied_unverified', $operation->refresh()->status);
        $this->assertSame(1, $this->writes);
        $this->assertNotNull(app(Proposals::class)->invalidReason($operation->publication->proposal, $operation->publication->revision));
    }

    public function test_invalid_receiver_identity_remains_unknown_until_the_original_receipt_is_reconciled(): void
    {
        $operation = $this->authorize($this->proposal());
        $this->corruptReceipt = true;
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->assertSame('outcome_unknown', $operation->refresh()->status);
        $this->assertNull($operation->committed_at);
        $this->assertNull($operation->publication->fresh()->applied_at);
        app(PageOperationDispatcher::class)->attempt($operation, reconcileOnly: true, force: true);
        $this->assertSame('applied_unverified', $operation->refresh()->status);
        $this->assertSame(1, $this->writes);
    }

    public function test_explicit_recovery_can_pin_rotated_credentials_on_the_same_destination(): void
    {
        $before = $this->cms;
        $operation = $this->authorize($this->proposal());
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->channel->update(['secret' => 'replacement-application-password']);
        $recovery = app(NativePublication::class)->recover($operation->publication->fresh(), $this->owner);
        $this->assertNotSame($operation->destination['connection_fingerprint'], $recovery->destination['connection_fingerprint']);
        $this->assertSame($operation->destination['account_name'], $recovery->destination['account_name']);
        app(PageOperationDispatcher::class)->attempt($recovery);
        $this->assertSame('applied_unverified', $recovery->refresh()->status);
        $this->assertSame($before, $this->cms);
        $this->assertSame(2, $this->writes);
    }

    public function test_recovery_refuses_an_intervening_external_edit(): void
    {
        $operation = $this->authorize($this->proposal());
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->cms['body_html'] .= '<p>External addition.</p>';
        $this->version++;
        try {
            app(NativePublication::class)->recover($operation->publication, $this->owner);
            $this->fail('An intervening external edit must stop recovery.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(1, $operation->publication->operations()->count());
            $this->assertSame(1, $this->writes);
        }
    }

    public function test_changed_reviewed_claim_fact_refuses_both_new_generation_and_reassessment(): void
    {
        $proposal = $this->proposal();
        $opportunity = $proposal->opportunity;
        $opportunity->update(['evidence_snapshot' => [...$opportunity->evidence_snapshot, 'diagnosis_mode' => 'reviewed_claim', 'confirmed_facts' => [['version_id' => $this->fact->current_version_id]]]]);
        app(BusinessFacts::class)->save($this->project, $this->owner, ['expected_version_id' => $this->fact->current_version_id, 'name' => 'Scope', 'statement' => 'Only the kitchen is included.', 'source_url' => 'https://example.com/service', 'source_note' => 'Changed owner policy.', 'status' => 'confirmed', 'confirm' => true, 'review_due_at' => now()->addDays(30)->toDateString()], $this->fact);
        foreach ([false, true] as $initial) {
            if ($initial) {
                $opportunity = PageOpportunity::query()->create([...$opportunity->only(['site_page_id', 'kind', 'diagnosed_issue', 'suggested_scope', 'evidence_snapshot', 'confidence', 'effort', 'ranking_factors', 'missing_fact_questions', 'overlap_page_ids', 'diagnosed_at']), 'status' => 'open', 'fingerprint' => hash('sha256', 'new-reviewed-claim')]);
            }
            try {
                app(Proposals::class)->begin($opportunity, $this->owner, $initial ? null : 'Reassess', $initial ? null : $proposal->current_revision_id);
                $this->fail('The changed owner review cannot start generation.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertStringContainsString('originating workflow', $exception->getMessage());
            }
        }
        $this->assertSame(1, PageProposal::query()->count());
    }

    public function test_wordpress_plugin_download_contains_only_installable_receiver_source(): void
    {
        $response = $this->get('/integrations/wordpress/receiver.zip')->assertOk()->assertDownload('avyo-receiver.zip');
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        // Every file the bootstrap includes, read from the bootstrap itself: a
        // download missing one activates into a fatal on the missing require,
        // and a count written out by hand here would not notice a new include.
        $bootstrap = (string) file_get_contents(base_path('packages/wordpress-receiver/plugin/avyo-receiver.php'));
        preg_match_all("/require_once __DIR__\\.'\\/([^']+)'/", $bootstrap, $matches);
        $required = ['avyo-receiver.php', ...$matches[1]];
        $this->assertContains('articles.php', $required);
        $this->assertSame(count($required), $zip->numFiles);
        foreach ($required as $name) {
            $this->assertSame(
                file_get_contents(base_path('packages/wordpress-receiver/plugin/'.$name)),
                $zip->getFromName('avyo-receiver/'.$name),
                "The download is missing {$name}, which the plugin bootstrap requires.",
            );
        }
        $zip->close();
        unlink($file);
    }

    public function test_an_unavailable_native_target_keeps_historical_proposal_readable(): void
    {
        $proposal = $this->proposal();
        $operation = $this->authorize($proposal);
        app(PageOperationDispatcher::class)->attempt($operation);
        $this->project->update(['website_url' => 'https://other.example']);
        $this->get('/proposals/'.$proposal->id)->assertOk()->assertInertia(fn ($page) => $page
            ->component('proposals/show')
            ->where('proposal.reason', 'The native connection is unavailable. Reconnect and review the destination, or use assistance.')
            ->has('proposal.publications', 1));
        $this->assertNotNull($operation->fresh()->committed_at);
    }

    private function proposal(string $kind = 'text_section'): PageProposal
    {
        $snapshot = $this->page->fresh()->latestSnapshot;
        $block = collect(app(PageBlocks::class)->from($snapshot))->firstWhere('text', 'Our cleaning service.');
        $change = ['kind' => $kind, 'operation' => 'replace', 'locator' => $kind === 'title' ? 'title' : $block['id'], 'before' => $kind === 'title' ? $snapshot->fields['title'] : $block['text'], 'after' => $kind === 'title' ? 'Cleaning homes – Company' : 'Cleaning includes floors and bathrooms.', 'reason' => 'Clarify the confirmed service.', 'fact_version_ids' => [$this->fact->current_version_id], 'target_page_id' => null, 'anchor_text' => null];
        $opportunity = PageOpportunity::query()->create(['site_page_id' => $this->page->id, 'kind' => 'missing_business_fact', 'diagnosed_issue' => 'Clarify cleaning scope', 'suggested_scope' => 'One supported field', 'evidence_snapshot' => ['snapshot_id' => $snapshot->id, 'canonical_url' => $this->page->canonical_url, 'locale' => 'en'], 'confidence' => 'low', 'effort' => 'small', 'ranking_factors' => [], 'missing_fact_questions' => [], 'overlap_page_ids' => [], 'status' => 'open', 'fingerprint' => hash('sha256', $kind), 'diagnosed_at' => now()]);
        $this->models->willAnswer([json_encode(['changes' => [$change], 'missing_facts' => []], JSON_THROW_ON_ERROR)]);

        return app(Proposals::class)->begin($opportunity, $this->owner);
    }

    private function authorize(PageProposal $proposal): PagePublicationOperation
    {
        app(Proposals::class)->accept($proposal, $this->owner, $proposal->current_revision_id, 20);

        return app(NativePublication::class)->authorize($proposal->fresh(), $this->owner, $proposal->current_revision_id);
    }

    /** @return array<string,mixed> */
    private function source(): array
    {
        return ['schema_v' => 1, 'object_id' => '12', 'object_type' => 'page', 'public_url' => 'https://example.com/service', 'revision' => hash('sha256', json_encode([$this->cms, $this->version], JSON_THROW_ON_ERROR)), 'fields' => [...$this->cms, 'body_text' => strip_tags($this->cms['body_html'])], 'editable_fields' => ['title', 'description', 'body_html'], 'metadata' => ['description_owner' => 'avyo', 'description_present' => true, 'preservation_hash' => hash('sha256', 'protected-original')], 'capabilities' => ['idempotency' => true, 'reconciliation' => true, 'recovery' => true, 'patch_operations' => ['replace', 'insert_after', 'link']], 'verification' => ['public_fetch_required' => true]];
    }

    private function publicHtml(): string
    {
        $card = $this->selfTitleCard ? '<ul><li><h3 class="wp-block-post-title"><a href="https://example.com/service">'.$this->cms['title'].'</a></h3><span>September 15</span></li></ul>' : '';

        return '<html lang="en"><head><title>'.$this->cms['title'].' – Company</title><meta name="description" content="'.$this->cms['description'].'"><link rel="canonical" href="https://example.com/service"></head><body><main><h1>'.$this->cms['title'].'</h1>'.$this->cms['body_html'].$card.'</main></body></html>';
    }
}
