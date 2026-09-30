<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Enums\ChannelType;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\DeliveryExplanation;
use App\Publishing\StrandedDeliveries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeliveryExplanationTest extends TestCase
{
    /** @return iterable<string, array{int|null, string|null, string}> */
    public static function failures(): iterable
    {
        yield 'wrong secret' => [401, 'receiver refused with 401', "rejected Avyo's signature"];
        yield 'wrong address' => [404, 'receiver refused with 404', 'Nothing answered at that address'];
        yield 'not a POST endpoint' => [405, 'receiver refused with 405', 'has to accept POST'];
        yield 'server error' => [503, 'receiver answered 503', 'Your website had an error (503)'];
        yield 'redirect' => [301, 'receiver refused with 301', 'redirects somewhere else'];
        yield 'receipt mismatch' => [200, 'receiver refused with 200', 'not the way Avyo expected'];
        yield 'timeout' => [null, 'cURL error 28: Operation timed out after 15001 milliseconds', 'took too long'];
        yield 'dns' => [null, 'cURL error 6: Could not resolve host: blog.example', "couldn't find that address"];
        yield 'refused' => [null, 'cURL error 7: Failed to connect: Connection refused', "didn't accept the connection"];
        yield 'tls' => [null, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', 'certificate'];
    }

    #[Test]
    #[DataProvider('failures')]
    public function a_failure_is_said_in_words_an_owner_can_act_on(?int $status, ?string $error, string $expected): void
    {
        $this->assertStringContainsString($expected, (string) DeliveryExplanation::explain($status, $error));
    }

    #[Test]
    public function wordpress_refusing_the_login_points_at_the_login_not_a_secret(): void
    {
        foreach ([401, 403] as $status) {
            $this->assertSame(
                "WordPress refused Avyo's login ({$status}). Check the username and application password.",
                DeliveryExplanation::explain($status, "receiver refused with {$status}", ChannelType::WordPress),
            );
        }

        // Everything else about a WordPress failure reads as before, and a
        // webhook's refusal is still about the secret.
        $this->assertSame('Your website had an error (503).', DeliveryExplanation::explain(503, null, ChannelType::WordPress));
        $this->assertStringContainsString("rejected Avyo's signature", (string) DeliveryExplanation::explain(401, null, ChannelType::Webhook));
    }

    #[Test]
    public function avyos_own_sentences_are_reworded_and_unknown_ones_are_not_shown(): void
    {
        $this->assertSame("It was sent back for changes, so it wasn't sent.",
            DeliveryExplanation::explain(null, 'The unit was sent back for rework before this delivery went out, so it was not sent.'));
        $this->assertSame('The fact check found something to look at. Review the article, then approve it.',
            DeliveryExplanation::explain(null, 'The fact check has not passed. Review the article before publishing.'));
        $this->assertStringStartsWith("Its schedule changed after it was queued, so it wasn't sent.",
            (string) DeliveryExplanation::explain(null, 'This delivery no longer matches the active, due publication schedule.'));
        $this->assertStringStartsWith("The article changed after it was queued, so it wasn't sent.",
            (string) DeliveryExplanation::explain(null, 'The article changed after this delivery was queued. Review the current article before publishing.'));
        $this->assertSame(DeliveryExplanation::DELAYED, DeliveryExplanation::explain(null, StrandedDeliveries::REQUEUED_MAYBE_SENT));
        $this->assertSame(DeliveryExplanation::GAVE_UP,
            DeliveryExplanation::explain(null, sprintf(StrandedDeliveries::ABANDONED_UNSENT, StrandedDeliveries::MAX_SWEEPS + 1)));

        // This commit's own refusals, said as they are or in its words.
        $this->assertSame(DeliveryExplanation::REPLAY_WEBSITE_NOT_WORKING, DeliveryExplanation::explain(null, DeliveryExplanation::REPLAY_WEBSITE_NOT_WORKING));
        $this->assertSame(DeliveryExplanation::REPLAY_WEBSITE_PAUSED, DeliveryExplanation::explain(null, DeliveryExplanation::REPLAY_WEBSITE_PAUSED));
        $this->assertSame('Approve it first. Avyo sends it again once you do.', DeliveryExplanation::explain(null, ArticleSchedules::NEEDS_APPROVAL));

        // Already written for owners.
        $facts = 'Your business information changed after this article was written. Update the article before publishing it.';
        $this->assertSame($facts, DeliveryExplanation::explain(null, $facts));

        // Nobody has named this one: a plain "couldn't", never the raw text.
        $this->assertSame("Avyo couldn't send it.", DeliveryExplanation::explain(null, 'Unexpected internal state 0x2f in publisher.'));
    }

    #[Test]
    public function nothing_to_explain_is_null(): void
    {
        $this->assertNull(DeliveryExplanation::explain(null, null));
        $this->assertNull(DeliveryExplanation::explain(null, '  '));
    }
}
