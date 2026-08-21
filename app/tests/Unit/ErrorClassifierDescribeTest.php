<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pipelines\Core\ErrorClassifier;
use App\Pipelines\Exceptions\RetryableStepFailure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The two halves of what a failed run leaves behind.
 *
 * `describe()` is read by the customer — the dashboard's failure card and the
 * studio operation payload are built from it — so it stops at the step's own
 * sentence. `causes()` is read by whoever has to fix it, goes to the log, and
 * carries the vendor's words.
 *
 * Both existed as one method that did the first job only, which is how five
 * Studio runs came to record "the Content Studio provider is temporarily
 * unavailable" — three attempts inside 639ms — over a provider that answered
 * every probe put to it afterwards.
 */
final class ErrorClassifierDescribeTest extends TestCase
{
    #[Test]
    public function it_describes_the_failure_without_what_it_wrapped(): void
    {
        $described = (new ErrorClassifier)->describe($this->wrapped());

        $this->assertSame('The Content Studio provider is temporarily unavailable.', $described['message']);
        $this->assertTrue($described['retryable']);
        $this->assertStringNotContainsString(
            'Incorrect API key',
            (string) json_encode($described),
        );
    }

    #[Test]
    public function it_walks_the_causes_for_the_log(): void
    {
        $causes = (new ErrorClassifier)->causes($this->wrapped());

        $this->assertCount(2, $causes);
        $this->assertSame('The openai call failed: 401 Incorrect API key provided.', $causes[0]['message']);
        $this->assertSame(RuntimeException::class, $causes[1]['class']);
        $this->assertStringContainsString(__FILE__, $causes[1]['file']);
    }

    #[Test]
    public function a_failure_that_wrapped_nothing_has_no_causes(): void
    {
        $this->assertSame([], (new ErrorClassifier)->causes(new RuntimeException('Plain.')));
    }

    #[Test]
    public function it_stops_walking_a_long_chain(): void
    {
        $e = new RuntimeException('root');

        for ($i = 0; $i < 20; $i++) {
            $e = new RuntimeException("wrap {$i}", previous: $e);
        }

        $this->assertCount(5, (new ErrorClassifier)->causes($e));
    }

    /**
     * The provider's own words are the point, so a cause carrying an HTTP
     * response keeps its status and body — trimmed harder than the failure
     * itself, because five of these ride along in one log line.
     */
    #[Test]
    public function it_keeps_a_trimmed_response_body_from_a_cause(): void
    {
        $causes = (new ErrorClassifier)->causes(
            new RetryableStepFailure('Wrapped.', previous: $this->requestException(429, str_repeat('x', 3000))),
        );

        $this->assertSame(429, $causes[0]['http_status']);
        $this->assertSame(500, mb_strlen($causes[0]['http_body']));
    }

    /**
     * The budget covers the sentence too, because for the provider that
     * matters most here it *is* the body: LarAgent hands on a vendor exception
     * with the response payload inside its message, and the gateway copies
     * that message into its own. Bounding `http_body` and not `message` bounds
     * nothing — five unbounded frames in one warning is a log line a pipeline
     * may drop whole, which loses the only copy of the cause.
     */
    #[Test]
    public function it_bounds_a_cause_whose_message_carries_the_payload(): void
    {
        $causes = (new ErrorClassifier)->causes(
            new RetryableStepFailure('Wrapped.', previous: new RuntimeException(
                'The openai call failed: '.str_repeat('y', 5000),
            )),
        );

        $this->assertSame(500, mb_strlen($causes[0]['message']));
    }

    #[Test]
    public function it_bounds_the_message_of_the_failure_itself(): void
    {
        $described = (new ErrorClassifier)->describe(new RuntimeException(str_repeat('y', 5000)));

        $this->assertSame(2000, mb_strlen($described['message']));
    }

    #[Test]
    public function it_keeps_more_of_the_body_of_the_failure_itself(): void
    {
        $described = (new ErrorClassifier)->describe($this->requestException(503, str_repeat('x', 3000)));

        $this->assertSame(503, $described['http_status']);
        $this->assertSame(2000, mb_strlen($described['http_body']));
        $this->assertTrue($described['retryable']);
    }

    /** What `ApplyContentStudioAction` throws, in the shape it throws it. */
    private function wrapped(): RetryableStepFailure
    {
        return new RetryableStepFailure(
            'The Content Studio provider is temporarily unavailable.',
            previous: new RetryableStepFailure(
                'The openai call failed: 401 Incorrect API key provided.',
                previous: new RuntimeException('Incorrect API key provided.'),
            ),
        );
    }

    private function requestException(int $status, string $body): RequestException
    {
        return new RequestException(new Response(new Psr7Response($status, [], $body)));
    }
}
