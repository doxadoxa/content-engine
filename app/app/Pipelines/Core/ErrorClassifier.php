<?php

declare(strict_types=1);

namespace App\Pipelines\Core;

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
     * A small, storable rendering of the failure. Small on purpose: this lands
     * in a json column that the panel of phase 7 renders, and provider error
     * bodies run to kilobytes of HTML.
     *
     * @return array<string, mixed>
     */
    public function describe(Throwable $e): array
    {
        $described = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'retryable' => $this->isRetryable($e),
        ];

        if ($e instanceof RequestException) {
            $described['http_status'] = $e->response->status();
            $described['http_body'] = mb_substr($e->response->body(), 0, 2000);
        }

        return $described;
    }
}
