<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\Articles\BlockedCode;
use App\Publishing\Articles\PublicationStatus;
use App\Publishing\DeliveryExplanation;
use App\Publishing\StrandedDeliveries;
use App\Publishing\WebhookPublisher;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One presenter says where an article is, for every screen. These pin each
 * state to the words and the next step an owner sees.
 */
final class PublicationStatusTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T08:00:00Z'));
        Queue::fake();
        $this->project = Project::factory()->create(['autopublish' => true, 'timezone' => 'UTC']);
        app(CurrentProject::class)->set($this->project);
        $this->channel = Channel::factory()->create(['type' => ChannelType::Webhook, 'name' => 'Main site',
            'config' => ['endpoint' => 'https://receiver.test/articles'], 'is_enabled' => true, 'verified_at' => now()]);
    }

    #[Test]
    public function an_automatic_article_on_course_is_scheduled_with_its_day_and_time(): void
    {
        $item = $this->article(ContentItemState::Draft, ['factcheck' => ['passed' => true]]);
        $this->schedule($item, '2026-09-16 09:00');

        $status = $this->present($item);

        $this->assertSame('scheduled', $status['key']);
        $this->assertSame('neutral', $status['tone']);
        $this->assertSame('Scheduled', $status['label']);
        $this->assertSame('Publishes tomorrow at 09:00. Avyo checks it first.', $status['detail']);
        $this->assertNull($status['action']);
    }

    #[Test]
    public function a_review_first_article_is_waiting_for_you_not_an_error(): void
    {
        $item = $this->article(ContentItemState::Draft);
        $this->schedule($item, '2026-09-18 09:00', ['mode' => 'review_first']);

        $status = $this->present($item);

        $this->assertSame('waiting', $status['key']);
        $this->assertSame('attention', $status['tone']);
        $this->assertSame('Waiting for you', $status['label']);
        $this->assertSame('Approve it to publish Fri 18 Sep at 09:00.', $status['detail']);
        $this->assertSame(['label' => 'Approve', 'kind' => 'approve', 'href' => "/content/{$item->id}/approve",
            'method' => 'post', 'owner_only' => false, 'external' => false], $status['action']);
    }

    #[Test]
    public function a_due_article_held_for_approval_says_when_it_was_due(): void
    {
        $item = $this->article(ContentItemState::Draft);
        $this->schedule($item, '2026-09-15 07:00', ['mode' => 'review_first', 'status' => 'blocked', 'blocked_reason' => ArticleSchedules::NEEDS_APPROVAL]);

        $status = $this->present($item);

        $this->assertSame('waiting', $status['key']);
        $this->assertSame('It was due today at 07:00. Approve it to publish now.', $status['detail']);
        $this->assertSame('approve', $status['action']['kind'] ?? null);
    }

    #[Test]
    public function a_young_pending_delivery_is_sending(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Pending, 'response_code' => null, 'attempts' => 0, 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:55', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame(['sending', 'progress', 'Sending', 'Sending to your website…'],
            [$status['key'], $status['tone'], $status['label'], $status['detail']]);
        $this->assertTrue(app(ArticleSchedules::class)->props($item->fresh())['in_flight']);
    }

    #[Test]
    public function a_stranded_delivery_is_delayed_and_says_avyo_will_try_again(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Pending, 'response_code' => null, 'attempts' => 0, 'delivered_at' => null]);
        $delivery->forceFill(['created_at' => now()->subMinutes(70)])->save();
        $this->schedule($item, '2026-09-15 06:50', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('delayed', $status['key']);
        $this->assertSame('attention', $status['tone']);
        $this->assertSame('Taking longer than usual. Avyo will try again automatically.', $status['detail']);
    }

    #[Test]
    public function a_retrying_delivery_explains_the_failure_and_the_next_try(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => 503, 'attempts' => 2,
            'error' => 'receiver answered 503', 'next_attempt_at' => CarbonImmutable::parse('2026-09-15T08:30:00Z'), 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('retrying', $status['key']);
        $this->assertSame('Retrying', $status['label']);
        $this->assertSame('Your website had an error (503). Avyo will try again today at 08:30.', $status['detail']);
        $this->assertSame('2026-09-15T08:30:00+00:00', $status['when']);
        $this->assertNull($status['action']);
    }

    #[Test]
    public function a_refused_signature_points_at_the_website_connection(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => 401, 'error' => 'receiver refused with 401', 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame(['failed', 'problem', "Couldn't publish"], [$status['key'], $status['tone'], $status['label']]);
        $this->assertStringContainsString("rejected Avyo's signature (401)", (string) $status['detail']);
        $this->assertSame(['connect', '/channels', true], [$status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['owner_only'] ?? null]);
    }

    #[Test]
    public function a_dead_letter_the_website_may_accept_later_offers_try_again(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => 500, 'attempts' => 5, 'error' => 'receiver answered 500', 'delivered_at' => null]);
        $this->schedule($item, '2026-09-14 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame('failed', $status['key']);
        $this->assertSame('Your website had an error (500).', $status['detail']);
        $this->assertSame(['Try again', 'retry', "/deliveries/{$delivery->id}/replay", 'post'],
            [$status['action']['label'] ?? null, $status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['method'] ?? null]);
    }

    #[Test]
    public function rows_without_a_code_fall_back_to_their_sentence(): void
    {
        $cases = [
            [ArticleSchedules::NO_WEBSITE, 'failed', 'connect', '/channels'],
            [ArticleSchedules::MISSED_DATE, 'waiting', 'reschedule', '#publication'],
            [ArticleSchedules::PAUSED, 'waiting', 'plan', '/billing'],
            ['The fact check has not passed. Review the article before publishing.', 'waiting', 'review', '/content/'],
            ['An active plan or available publication grace is required.', 'waiting', 'plan', '/billing'],
            ['No articles remain in this period. The draft stays saved.', 'waiting', 'plan', '/billing'],
            [ArticleSchedules::CHOOSE_WEBSITE, 'waiting', 'reschedule', '#publication'],
        ];

        foreach ($cases as [$reason, $key, $kind, $href]) {
            $item = $this->article(ContentItemState::Draft);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_reason' => $reason]);

            $status = $this->present($item);

            $this->assertSame($key, $status['key'], $reason);
            $this->assertSame($kind, $status['action']['kind'] ?? null, $reason);
            $this->assertStringContainsString($href, (string) ($status['action']['href'] ?? ''), $reason);
            $this->assertStringNotContainsString('delivery', strtolower((string) $status['detail']), $reason);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5: string}> */
    public static function codes(): array
    {
        // code => [key, label, action label, action kind, href fragment, detail fragment]
        return [
            'no_website' => [BlockedCode::NO_WEBSITE, 'failed', "Couldn't publish", 'Check website connection', '/channels', "connection isn't working"],
            'choose_website' => [BlockedCode::CHOOSE_WEBSITE, 'waiting', 'Waiting for you', 'Choose a website', '#publication', 'Choose which website'],
            'missed_date' => [BlockedCode::MISSED_DATE, 'waiting', 'Waiting for you', 'Pick a new date', '#publication', 'Its date passed'],
            'plan' => [BlockedCode::PLAN, 'waiting', 'Waiting for you', 'Choose a plan', '/billing', "Your plan doesn't include publishing yet."],
            'allowance_used' => [BlockedCode::ALLOWANCE_USED, 'waiting', 'Waiting for you', 'Choose a plan', '/billing', "You've used this period's articles. It will publish when your allowance renews, or choose a bigger plan."],
            'project_paused' => [BlockedCode::PROJECT_PAUSED, 'paused', 'Paused', 'Resume content work', '/edit', 'Content work is paused'],
            'needs_approval' => [BlockedCode::NEEDS_APPROVAL, 'waiting', 'Waiting for you', 'Approve', '/approve', 'Approve it to publish now.'],
            'fact_check' => [BlockedCode::FACT_CHECK, 'waiting', 'Waiting for you', 'Review article', '/content/', 'The fact check found something'],
            'score' => [BlockedCode::SCORE, 'waiting', 'Waiting for you', 'Review article', '/content/', 'This draft needs attention: add a picture.'],
            'business_facts' => [BlockedCode::BUSINESS_FACTS, 'waiting', 'Waiting for you', 'Review article', '/content/', 'This draft needs attention: add a picture.'],
            'website_not_working' => [BlockedCode::WEBSITE_NOT_WORKING, 'failed', "Couldn't publish", 'Check website connection', '/channels', "Your website connection isn't working. Test it on the Website page."],
            'website_paused' => [BlockedCode::WEBSITE_PAUSED, 'paused', 'Paused', 'Open website connection', '/channels', 'Your website connection is paused. Resume it to publish.'],
            'previous_delivery' => [BlockedCode::PREVIOUS_DELIVERY, 'failed', "Couldn't publish", 'Try again', '/replay', 'Your website had an error (500).'],
            'other' => [BlockedCode::OTHER, 'failed', "Couldn't publish", null, null, 'This draft needs attention: add a picture.'],
        ];
    }

    #[Test]
    #[DataProvider('codes')]
    public function each_blocked_code_has_its_own_words_and_fix(string $code, string $key, string $label, ?string $action, ?string $href, string $detail): void
    {
        $item = $this->article(ContentItemState::Draft);
        // The stored sentence says something else on purpose: the code wins.
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_code' => $code,
            'blocked_reason' => 'This draft needs attention: add a picture.']);
        if ($code === BlockedCode::PREVIOUS_DELIVERY) {
            $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => 500, 'error' => 'receiver answered 500', 'delivered_at' => null]);
        }

        $status = $this->present($item);

        $this->assertSame([$key, $label], [$status['key'], $status['label']]);
        $this->assertSame($action, $status['action']['label'] ?? null);
        if ($href !== null) {
            $this->assertStringContainsString($href, (string) ($status['action']['href'] ?? ''));
        }
        $this->assertStringContainsString($detail, (string) $status['detail']);
    }

    #[Test]
    public function other_without_a_sentence_is_still_being_prepared(): void
    {
        $item = $this->article(ContentItemState::Generating);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_code' => BlockedCode::OTHER,
            'blocked_reason' => ArticleSchedules::STILL_WRITING]);

        $status = $this->present($item);

        $this->assertSame(['writing', 'Writing', 'The article is still being prepared.'], [$status['key'], $status['label'], $status['detail']]);
    }

    #[Test]
    public function the_sweepers_own_words_are_never_shown(): void
    {
        foreach ([StrandedDeliveries::REQUEUED_UNSENT, StrandedDeliveries::REQUEUED_MAYBE_SENT] as $note) {
            $item = $this->article(ContentItemState::Approved);
            $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => null, 'error' => $note,
                'next_attempt_at' => now(), 'delivered_at' => null]);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

            $status = $this->present($item);
            $row = PublicationStatus::attempt($delivery->fresh() ?? $delivery, 'UTC');

            $this->assertSame(['delayed', 'Delayed', 'Taking longer than usual. Avyo will try again automatically.', null],
                [$status['key'], $status['label'], $status['detail'], $status['action']], $note);
            $this->assertSame(['Delayed', 'Taking longer than usual. Avyo will try again automatically.', null], [$row['label'], $row['explanation'], $row['next_attempt']]);
        }

        foreach ([StrandedDeliveries::ABANDONED_UNSENT, StrandedDeliveries::ABANDONED_MAYBE_SENT] as $format) {
            $item = $this->article(ContentItemState::Approved);
            $dead = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => null, 'delivered_at' => null,
                'error' => sprintf($format, StrandedDeliveries::MAX_SWEEPS + 1)]);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $dead->id]);

            $status = $this->present($item);

            $this->assertSame(["Couldn't publish", "Avyo couldn't get it to your website after several tries.", 'Try again'],
                [$status['label'], $status['detail'], $status['action']['label'] ?? null], $format);
        }
    }

    #[Test]
    public function a_row_swept_before_the_sweepers_words_were_constants_is_still_delayed(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => null, 'delivered_at' => null, 'next_attempt_at' => now(),
            'error' => 'The worker holding this delivery never reported back — it was most likely killed mid-flight. Nothing was sent; the delivery has been put back in the queue.']);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $this->assertTrue(StrandedDeliveries::isRequeueNote($delivery->error));
        $this->assertSame(['delayed', 'Taking longer than usual. Avyo will try again automatically.'],
            [$this->present($item)['key'], $this->present($item)['detail']]);
    }

    #[Test]
    public function a_handed_back_attempt_is_not_offered_try_again_anywhere(): void
    {
        $item = $this->article(ContentItemState::Draft);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => null, 'attempts' => 1,
            'error' => ArticleSchedules::NEEDS_APPROVAL, 'delivered_at' => null]);
        $schedule = $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_code' => BlockedCode::NEEDS_APPROVAL,
            'blocked_reason' => ArticleSchedules::NEEDS_APPROVAL, 'delivery_id' => $delivery->id]);

        $this->assertFalse(PublicationStatus::canTryAgain($delivery, $schedule));
        // Without the schedule it would have been offered: the schedule is what knows.
        $this->assertTrue(PublicationStatus::canTryAgain($delivery));
        $this->assertSame('Approve it first. Avyo sends it again once you do.', PublicationStatus::attempt($delivery, 'UTC')['explanation']);
    }

    #[Test]
    public function the_guard_sentence_about_a_changed_article_is_said_plainly(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_code' => null,
            'blocked_reason' => 'The article changed after this delivery was queued. Review the current article before publishing.']);

        $status = $this->present($item);

        $this->assertSame("The article changed after it was queued, so it wasn't sent. Review it, then publish it again.", $status['detail']);
        $this->assertStringNotContainsString('delivery', (string) $status['detail']);
    }

    #[Test]
    public function an_article_waiting_for_a_paused_website_says_to_resume_it(): void
    {
        $note = WebhookPublisher::WAITING_FOR_RESUME;
        $this->assertSame('Your website is paused. Resume it and Avyo will send it.', DeliveryExplanation::explain(null, $note));
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => null, 'attempts' => 0, 'deferrals' => 1,
            'error' => $note, 'next_attempt_at' => now()->addHours(3), 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);

        $this->assertSame(['waiting_website', 'Waiting for your website', 'Your website is paused. Resume it and Avyo will send it.', '/channels'],
            [$status['key'], $status['label'], $status['detail'], $status['action']['href'] ?? null]);
        $this->assertSame('Your website is paused. Resume it and Avyo will send it.', PublicationStatus::attempt($delivery, 'UTC')['explanation']);
    }

    #[Test]
    public function an_article_handed_back_after_an_attempt_waits_for_approval_without_try_again(): void
    {
        foreach ([BlockedCode::NEEDS_APPROVAL => 'Approve it to send it again.',
            BlockedCode::FACT_CHECK => 'The fact check found something to look at. Review it, then approve to send it again.'] as $code => $detail) {
            $item = $this->article(ContentItemState::Draft);
            $delivery = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => null, 'attempts' => 1,
                'error' => 'Approve the article first. Avyo sends it as soon as you do.', 'delivered_at' => null]);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_code' => $code,
                'blocked_reason' => ArticleSchedules::NEEDS_APPROVAL, 'delivery_id' => $delivery->id]);

            $status = $this->present($item);

            $this->assertSame(['waiting', 'Waiting for you', $detail], [$status['key'], $status['label'], $status['detail']]);
            $this->assertSame(['Approve', 'approve'], [$status['action']['label'] ?? null, $status['action']['kind'] ?? null]);
            $this->assertNull($status['secondary']);
        }
    }

    #[Test]
    public function an_article_waiting_for_a_broken_website_says_so_and_then_gives_up_plainly(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $delivery = $this->delivery($item, ['status' => DeliveryStatus::Retrying, 'response_code' => null, 'attempts' => 0, 'deferrals' => 2,
            'error' => WebhookPublisher::WAITING_FOR_WEBSITE, 'next_attempt_at' => now()->addHours(3), 'delivered_at' => null]);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id]);

        $status = $this->present($item);
        $row = PublicationStatus::attempt($delivery->fresh() ?? $delivery, 'UTC');

        $this->assertSame(['waiting_website', 'Waiting for your website', 'Avyo will send it as soon as your website connection passes a test.'],
            [$status['key'], $status['label'], $status['detail']]);
        $this->assertSame(['Check website connection', '/channels'], [$status['action']['label'] ?? null, $status['action']['href'] ?? null]);
        $this->assertSame(['Waiting for your website', null], [$row['label'], $row['next_attempt']]);

        $delivery->forceFill(['status' => DeliveryStatus::DeadLetter, 'error' => WebhookPublisher::WEBSITE_STAYED_BROKEN, 'next_attempt_at' => null])->save();

        $status = $this->present($item);

        $this->assertSame("Couldn't publish", $status['label']);
        $this->assertStringContainsString('stayed broken for a day', (string) $status['detail']);
        $this->assertSame('Check website connection', $status['action']['label'] ?? null);
        $this->assertSame(['Try again', "/deliveries/{$delivery->id}/replay"], [$status['secondary']['label'] ?? null, $status['secondary']['href'] ?? null]);
    }

    #[Test]
    public function internal_sentences_never_reach_the_owner_raw(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $back = $this->delivery($item, ['status' => DeliveryStatus::DeadLetter, 'response_code' => null, 'delivered_at' => null,
            'error' => 'The unit was sent back for rework before this delivery went out, so it was not sent.']);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $back->id]);

        $status = $this->present($item);

        $this->assertSame(['withdrawn', 'Not sent', "It was sent back for changes, so it wasn't sent.", null],
            [$status['key'], $status['label'], $status['detail'], $status['action']]);
        $this->assertFalse(PublicationStatus::canTryAgain($back));
        $this->assertSame('Not sent', PublicationStatus::attempt($back, 'UTC')['label']);

        $other = $this->article(ContentItemState::Approved);
        $odd = $this->delivery($other, ['status' => DeliveryStatus::DeadLetter, 'response_code' => null, 'delivered_at' => null,
            'error' => 'Unexpected internal state 0x2f in publisher.']);
        $this->schedule($other, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $odd->id]);

        $status = $this->present($other);

        $this->assertSame(["Avyo couldn't send it.", 'Try again'], [$status['detail'], $status['action']['label'] ?? null]);
        $this->assertTrue(PublicationStatus::canTryAgain($odd));
    }

    #[Test]
    public function a_wordpress_refusal_is_explained_as_a_login_in_every_list(): void
    {
        $wordpress = Channel::factory()->create(['type' => ChannelType::WordPress, 'name' => 'Blog', 'is_enabled' => true, 'verified_at' => now(),
            'config' => ['url' => 'https://blog.test', 'username' => 'avyo', 'article_publishing_verified' => true]]);
        $items = [];
        foreach ([1, 2] as $n) {
            $item = $this->article(ContentItemState::Approved);
            $delivery = WebhookDelivery::factory()->create(['channel_id' => $wordpress->id, 'content_item_id' => $item->id,
                'payload_snapshot' => ['event' => 'content.published'], 'status' => DeliveryStatus::DeadLetter,
                'response_code' => 401, 'error' => 'receiver refused with 401', 'delivered_at' => null]);
            $this->schedule($item, '2026-09-15 07:00', ['status' => 'dispatching', 'delivery_id' => $delivery->id, 'channel_id' => $wordpress->id]);
            $items[] = $item->id;
        }

        // Loaded as a list, the way every screen loads them, so a lazy load
        // of the website would throw here outside production.
        // Also without the website eager-loaded: the presenter loads it itself.
        foreach (['articleSchedule.delivery.channel', 'articleSchedule.delivery'] as $with) {
            $loaded = ContentItem::query()->whereIn('id', $items)->with([$with, 'project.channels'])->get();
            foreach ($loaded as $item) {
                $status = app(ArticleSchedules::class)->props($item)['presentation'];
                $this->assertStringStartsWith("WordPress refused Avyo's login (401)", (string) $status['detail'], $with);
            }
        }
    }

    #[Test]
    public function a_paused_business_asks_to_resume_rather_than_to_buy_a_plan(): void
    {
        $this->project->forceFill(['status' => ProjectStatus::Paused])->save();
        $item = $this->article(ContentItemState::Approved);
        $this->schedule($item, '2026-09-15 07:00', ['status' => 'blocked', 'blocked_reason' => ArticleSchedules::PAUSED]);

        $status = $this->present($item);

        $this->assertSame('paused', $status['key']);
        $this->assertSame('settings', $status['action']['kind'] ?? null);
    }

    #[Test]
    public function a_published_article_links_to_the_live_page(): void
    {
        $item = $this->article(ContentItemState::Published, ['public_url' => 'https://example.test/post', 'published_at' => now()->subDay()]);

        $status = $this->present($item);

        $this->assertSame(['published', 'success', 'Published', 'Published 14 Sep 2026.'],
            [$status['key'], $status['tone'], $status['label'], $status['detail']]);
        $this->assertSame(['View on your site', 'view', 'https://example.test/post', true],
            [$status['action']['label'] ?? null, $status['action']['kind'] ?? null, $status['action']['href'] ?? null, $status['action']['external'] ?? null]);
    }

    #[Test]
    public function the_quiet_states_are_plain(): void
    {
        $this->assertSame('planned', $this->present($this->article(ContentItemState::Idea))['key']);
        $this->assertSame('Writing', $this->present($this->article(ContentItemState::Queued))['label']);
        $this->assertSame('ready', $this->present($this->article(ContentItemState::Approved))['key']);

        $paused = $this->article(ContentItemState::Approved);
        $this->schedule($paused, '2026-09-16 09:00', ['status' => 'paused']);
        $this->assertSame('Paused', $this->present($paused)['label']);

        $canceled = $this->article(ContentItemState::Approved);
        $this->schedule($canceled, '2026-09-16 09:00', ['status' => 'canceled']);
        $this->assertSame('Not scheduled', $this->present($canceled)['label']);
    }

    #[Test]
    public function the_props_carry_the_presentation_and_keep_their_old_keys(): void
    {
        $item = $this->article(ContentItemState::Approved);
        $this->schedule($item, '2026-09-16 09:00');

        $props = app(ArticleSchedules::class)->props($item);

        $this->assertSame('scheduled', $props['status']);
        $this->assertSame('scheduled', $props['presentation']['key']);
        $this->assertFalse($props['in_flight']);
        $this->assertSame(['available' => true, 'reason' => null], $props['publish_now']);
        $this->assertArrayHasKey('can_schedule', $props);
    }

    /** @return array<string, mixed> */
    private function present(ContentItem $item): array
    {
        return PublicationStatus::for($item->fresh() ?? $item);
    }

    /** @param array<string, mixed> $attributes */
    private function article(ContentItemState $state, array $attributes = []): ContentItem
    {
        return ContentItem::factory()->create(['state' => $state, 'body_html' => '<p>Text.</p>', ...$attributes]);
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(ContentItem $item, string $at, array $attributes = []): ArticleSchedule
    {
        $when = CarbonImmutable::parse($at, 'UTC');

        return ArticleSchedule::query()->create([
            'content_item_id' => $item->id, 'channel_id' => $this->channel->id, 'publish_at' => $when,
            'local_date' => $when->toDateString(), 'local_time' => $when->format('H:i'), 'timezone' => 'UTC',
            'mode' => 'automatic', 'held_for_review' => false, 'origin' => 'manager', 'status' => 'active', 'version' => 1,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function delivery(ContentItem $item, array $attributes): WebhookDelivery
    {
        return WebhookDelivery::factory()->create(['channel_id' => $this->channel->id, 'content_item_id' => $item->id,
            'payload_snapshot' => ['event' => 'content.published'], ...$attributes]);
    }
}
