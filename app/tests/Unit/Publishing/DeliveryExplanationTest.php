<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Publishing\DeliveryExplanation;
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
    public function a_sentence_already_written_for_people_passes_through(): void
    {
        $message = 'The fact check has not passed. Review the article before publishing.';

        $this->assertSame($message, DeliveryExplanation::explain(null, $message));
    }

    #[Test]
    public function nothing_to_explain_is_null(): void
    {
        $this->assertNull(DeliveryExplanation::explain(null, null));
        $this->assertNull(DeliveryExplanation::explain(null, '  '));
    }
}
