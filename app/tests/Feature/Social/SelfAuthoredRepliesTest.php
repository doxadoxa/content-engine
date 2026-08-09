<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Integrations\Threads\ThreadsSelfAuthor;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Pipelines\Core\PipelineRunner;
use App\Pipelines\Steps\SocialListen\FetchMentions;
use App\Social\Jobs\DraftInteractionReplyJob;
use App\Support\Tenancy\CurrentProject;
use Database\Factories\ProjectIntegrationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The engine does not reply to itself (§4.1, §4.2).
 *
 * Both intakes of the reply contour see the account's own answers. Meta pushes
 * back what we post, and `GET /{user-id}/replies` reads a thread rather than
 * reading strangers. Stored, either one becomes a `new` interaction, which
 * starts {@see DraftInteractionReplyJob}, which produces a reply for the
 * operator to send, which arrives here again — a loop with a model call on
 * every turn.
 *
 * The search intake was already right: `FetchSearch` marks its own posts and
 * `Normalise` refuses to classify them as anybody's question. These are the
 * same rule on the other two.
 */
final class SelfAuthoredRepliesTest extends TestCase
{
    use RefreshDatabase;

    /** What {@see ProjectIntegrationFactory::threads()} connects. */
    private const string USER_ID = '17841400000000000';

    private const string USERNAME = 'brandname';

    private const string WEBHOOK = '/api/threads/webhook';

    private const string SECRET = 'threads-webhook-secret';

    private Project $project;

    private ProjectIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-09 12:00:00');

        $this->project = Project::factory()->create(['feed_urls' => []]);
        app(CurrentProject::class)->set($this->project);

        config()->set('services.threads.client_id', 'threads-app-id');
        config()->set('services.threads.client_secret', 'threads-app-secret');
        config()->set('services.threads.webhook_secret', self::SECRET);
        config()->set('queue.default', 'sync');

        Channel::factory()->threads()->create(['verified_at' => now()]);
        $this->integration = ProjectIntegration::factory()->threads()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------- the webhook

    #[Test]
    public function a_webhook_event_the_connected_account_wrote_becomes_nothing(): void
    {
        Queue::fake();

        $this->postEvent($this->reply('reply-1', self::USERNAME))->assertOk();

        $this->assertSame(0, Interaction::acrossProjects()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_same_handle_written_differently_is_still_us(): void
    {
        Queue::fake();

        // A handle is written `@brandname` as often as `brandname`, and Meta
        // is not consistent about case. A filter that only matches one spelling
        // is a filter that stops working the first time the platform changes
        // its mind.
        $this->postEvent($this->reply('reply-1', '@BrandName'))->assertOk();

        $this->assertSame(0, Interaction::acrossProjects()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_reply_from_anybody_else_is_still_a_conversation(): void
    {
        Queue::fake();

        $this->postEvent($this->reply('reply-1', 'asker'))->assertOk();

        $this->assertSame(1, Interaction::acrossProjects()->count());
        Queue::assertPushed(DraftInteractionReplyJob::class, 1);
    }

    #[Test]
    public function an_author_id_settles_it_even_when_the_handle_has_changed(): void
    {
        Queue::fake();

        // A username can be changed and an id cannot, so where the payload
        // carries both the id is what decides.
        $value = $this->replyValue('reply-1', 'renamed-since');
        $value['from'] = ['id' => self::USER_ID, 'username' => 'renamed-since'];

        $this->postEvent($this->envelope($value))->assertOk();

        $this->assertSame(0, Interaction::acrossProjects()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_connection_with_no_username_on_it_keeps_listening(): void
    {
        Queue::fake();

        $this->integration->forceFill(['config' => ['user_id' => self::USER_ID]])->save();

        // Deliberately the tolerant direction. "We do not know who we are"
        // read as "everything is ours" would drop every conversation on the
        // project and leave §4.2's queue permanently empty with nothing failing.
        $this->postEvent($this->reply('reply-1', 'asker'))->assertOk();

        $this->assertSame(1, Interaction::acrossProjects()->count());
    }

    // ------------------------------------------------- the reconciliation pass

    #[Test]
    public function the_reconciliation_pass_skips_our_own_replies(): void
    {
        // Only this job. A blanket fake would swallow the pipeline's own steps
        // and the run would never execute.
        Queue::fake([DraftInteractionReplyJob::class]);

        $this->seedVocabulary();
        $this->fakeThreads([
            $this->apiReply('reply-1', 'Vinegar is fine on tiles, not on marble.', self::USERNAME),
            $this->apiReply('reply-2', 'Does vinegar actually work on this?', 'asker'),
        ]);

        $run = $this->listen();

        $this->assertSame(['reply-2'], Interaction::query()->pluck('external_id')->all());
        $this->assertSame(1, $this->payload($run, FetchMentions::key())['recovered']);
        Queue::assertPushed(DraftInteractionReplyJob::class, 1);
    }

    #[Test]
    public function our_own_reply_is_not_counted_as_a_delivery_the_webhook_missed(): void
    {
        Queue::fake([DraftInteractionReplyJob::class]);

        $this->seedVocabulary();
        $this->fakeThreads([
            $this->apiReply('reply-1', 'Vinegar is fine on tiles, not on marble.', self::USERNAME),
        ]);

        $run = $this->listen();

        // `recovered` is the only evidence anyone gets that Meta is dropping
        // deliveries. Counting our own posts in it would make that number
        // permanently non-zero and therefore useless.
        $this->assertSame(0, $this->payload($run, FetchMentions::key())['recovered']);
        $this->assertSame(0, Interaction::query()->count());
    }

    // ---------------------------------------------------------------- the rule

    #[Test]
    public function a_reply_id_is_never_mistaken_for_an_author_id(): void
    {
        // Every reply event has an `id` and it is the reply's, not the author's.
        // Reading it as an author would compare a post id against an account id
        // and answer "not ours" forever — or, on the day the two collide, drop a
        // stranger's reply.
        $this->assertFalse(ThreadsSelfAuthor::wrote($this->integration, [
            'id' => self::USER_ID,
            'username' => 'asker',
        ]));
    }

    // ----------------------------------------------------------------- setup

    private function listen(): PipelineRun
    {
        $run = app(PipelineRunner::class)->start('social_listen', $this->project);

        $failed = $run->steps()
            ->whereNotNull('error')
            ->pluck('error', 'step_key')
            ->all();

        $this->assertSame([], $failed, 'The listening run did not complete.');

        return $run;
    }

    /** @return array<string, mixed> */
    private function payload(PipelineRun $run, string $stepKey): array
    {
        /** @var PipelineStep $step */
        $step = $run->steps()->where('step_key', $stepKey)->firstOrFail();

        return is_array($step->output) ? $step->output : [];
    }

    /**
     * One unit, so the project has a vocabulary and the listening run has
     * something to ask about.
     */
    private function seedVocabulary(): void
    {
        ContentItem::factory()->create([
            'title' => 'How to descale a kettle',
            'target_query' => 'how to descale a kettle',
            'entities' => ['limescale'],
            'cluster' => 'limescale',
        ]);
    }

    /** @param list<array<string, mixed>> $replies */
    private function fakeThreads(array $replies): void
    {
        Http::fake([
            'graph.threads.net/*' => function (Request $request) use ($replies): mixed {
                return str_contains($request->url(), '/replies')
                    ? Http::response(['data' => $replies])
                    : Http::response(['data' => []]);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function apiReply(string $id, string $text, string $username): array
    {
        return [
            'id' => $id,
            'text' => $text,
            'username' => $username,
            'permalink' => "https://www.threads.net/@{$username}/post/{$id}",
            'timestamp' => '2026-08-09T11:00:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
        ];
    }

    /** @return array<string, mixed> */
    private function reply(string $id, string $username): array
    {
        return $this->envelope($this->replyValue($id, $username));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function envelope(array $value): array
    {
        return [
            'object' => 'user',
            'entry' => [[
                'id' => self::USER_ID,
                'time' => 1_786_000_000,
                'changes' => [['field' => 'replies', 'value' => $value]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function replyValue(string $id, string $username): array
    {
        return [
            'id' => $id,
            'text' => 'Does vinegar actually work on this?',
            'username' => $username,
            'permalink' => "https://www.threads.net/@{$username}/post/{$id}",
            'timestamp' => '2026-08-09T08:15:00+0000',
            'replied_to' => ['id' => 'parent-1'],
            'root_post' => ['id' => 'root-1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function postEvent(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', self::WEBHOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }
}
