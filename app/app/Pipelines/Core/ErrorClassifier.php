<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

use App\Ai\LaragentModelGateway;
use App\Integrations\Exceptions\GoogleUnavailable;
use App\Pipelines\Exceptions\InvalidPipelineDefinition;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Retryable, or not (§3.1).
 *
 * The default is *not* to retry. An exception nobody classified is usually a
 * bug in a step, and running a bug three times with backoff spends provider
 * credits to reach the same failure fifteen minutes later. Steps that know
 * better say so by throwing {@see RetryableStepFailure}.
 */
class ErrorClassifier
{
    /** "Come back later", as opposed to "you asked wrong". */
    private const array RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504, 529];

    /** Deep enough for gateway-wraps-vendor-wraps-curl, shallow enough to stay a summary. */
    private const int MAX_CAUSES = 5;

    /**
     * Per frame, and applied to the message as well as to the body.
     *
     * Capping the body alone bounds nothing: an SDK that cannot attach its
     * response to the exception puts it in the sentence instead, and
     * {@see LaragentModelGateway} copies that sentence into its own.
     * A frame is only as small as its largest field.
     */
    private const int TEXT_CHARS = 2000;

    /**
     * Less of a cause than of the failure itself: the useful part of a
     * provider's error is its first line, and five of these ride along in one
     * log line, which a log pipeline is entitled to drop for length — losing
     * exactly the diagnostic this class exists to keep.
     */
    private const int CAUSE_TEXT_CHARS = 500;

    public function isRetryable(Throwable $e): bool
    {
        return match (true) {
            $e instanceof RetryableStepFailure => true,

            $e instanceof TerminalStepFailure,
            $e instanceof InvalidPipelineDefinition,
            $e instanceof ValidationException => false,

            // DNS failure, refused connection, read timeout.
            $e instanceof ConnectionException => true,

            // Google was down or rate-limiting. Thrown only for the transient
            // cases — a misconfigured OAuth client is a plain RuntimeException
            // and falls through to terminal, because no amount of waiting
            // fixes a wrong client secret.
            $e instanceof GoogleUnavailable => true,

            $e instanceof RequestException => in_array(
                $e->response->status(),
                self::RETRYABLE_STATUSES,
                true,
            ),

            default => false,
        };
    }

    /**
     * A small, storable rendering of the failure.
     *
     * Small on purpose: this lands in a json column that the panel of phase 7
     * renders, and provider error bodies run to kilobytes of HTML.
     *
     * Deliberately *not* the causes. A step that wraps a provider error in a
     * sentence of its own is choosing what the customer reads, and this array
     * is what the customer reads — the studio operation payload and the
     * dashboard's failure card are both built from it. The cause goes to the
     * log instead; see {@see causes()}.
     *
     * @return array<string, mixed>
     */
    public function describe(Throwable $e): array
    {
        $described = $this->frame($e, self::TEXT_CHARS);
        $described['retryable'] = $this->isRetryable($e);

        return $described;
    }

    /**
     * What the failure wrapped, outermost first — for the log, not the column.
     *
     * Nothing recorded this, and a step whose whole error handling is one
     * reassuring sentence left nothing else: five Studio runs stored "the
     * provider is temporarily unavailable" over a provider that answered every
     * probe, three attempts inside 639ms, and the only other witness was a log
     * line carrying that same sentence as its `exception` field. Whoever picks
     * this up next needs the vendor's own words.
     *
     * @return list<array<string, mixed>>
     */
    public function causes(Throwable $e): array
    {
        $causes = [];

        for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if (count($causes) >= self::MAX_CAUSES) {
                break;
            }

            $causes[] = $this->frame($cause, self::CAUSE_TEXT_CHARS);
        }

        return $causes;
    }

    /**
     * One exception, without its causes and within a budget.
     *
     * @return array<string, mixed>
     */
    private function frame(Throwable $e, int $chars): array
    {
        $frame = [
            'class' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, $chars),
            'file' => $e->getFile().':'.$e->getLine(),
        ];

        if ($e instanceof RequestException) {
            $frame['http_status'] = $e->response->status();
            $frame['http_body'] = mb_substr($e->response->body(), 0, $chars);
        }

        return $frame;
    }
}
