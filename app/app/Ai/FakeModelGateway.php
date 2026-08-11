<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Contracts\ModelGateway;
use RuntimeException;

/**
 * The gateway the test suite runs against (§3.3: "набор тестов в сеть не ходит
 * ни в одной фазе").
 *
 * It is deliberately not a null object. It reports token counts and a model
 * name, so the costing path — catalog lookup, price list version, micro-USD
 * arithmetic — is exercised by every pipeline test rather than by a handful of
 * unit tests about arithmetic. `fake-model` is priced in config/models.php for
 * that reason.
 */
class FakeModelGateway implements ModelGateway
{
    public const string MODEL = 'fake-model';

    /** @var list<ModelRequest> */
    private array $sent = [];

    /** @var list<string> */
    private array $answers = [];

    /** @var array<string, string> */
    private array $byRole = [];

    private ?\Closure $failure = null;

    private ?\Closure $answer = null;

    /**
     * Answer by looking at the request.
     *
     * Neither {@see willAnswer()} nor {@see willAnswerRole()} survives a caller
     * that makes many calls in one role and needs different answers to them —
     * which is what a candidate pool is. The Content Studio writes four drafts
     * per channel for three channels in the `draft` role alone, so a positional
     * queue means scripting twelve answers in the order a loop happens to take
     * them, and a role map means all twelve are identical.
     *
     * The closure receives the {@see ModelRequest} and may return null to fall
     * through to the role map, then the queue, then the echo — so a test can
     * special-case one kind of call and leave the rest alone.
     */
    public function willAnswerUsing(\Closure $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    /**
     * Queue the answers to hand back, in order. Once they run out the gateway
     * echoes the prompt, so a test that only cares about token accounting does
     * not have to script every call.
     *
     * @param  list<string>  $answers
     */
    public function willAnswer(array $answers): self
    {
        $this->answers = $answers;

        return $this;
    }

    /**
     * Answer by role rather than by position.
     *
     * The queue in {@see willAnswer()} assumes the caller knows the order calls
     * happen in, which stops being true the moment a pipeline fans out: two
     * parallel branches both calling a model have no guaranteed order between
     * them, and a test that assumed one would pass or fail on the graph's
     * alphabetical tie-break. Roles are stable.
     */
    public function willAnswerRole(string $role, string $text): self
    {
        $this->byRole[$role] = $text;

        return $this;
    }

    /** Make the next call throw, for the retry and failure paths. */
    public function willThrow(\Closure $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function send(ModelRequest $request): ModelResponse
    {
        $this->sent[] = $request;

        if ($this->failure !== null) {
            $failure = $this->failure;

            throw $failure($request);
        }

        $scripted = $this->answer === null ? null : ($this->answer)($request);

        $text = $scripted
            ?? $this->byRole[$request->role]
            ?? array_shift($this->answers)
            ?? "echo: {$request->prompt}";

        return new ModelResponse(
            text: $text,
            provider: 'fake',
            model: self::MODEL,
            role: $request->role,
            // Deterministic and derived from the payload, so a test can assert
            // an exact cost without the numbers being arbitrary.
            inputTokens: mb_strlen($request->instructions.$request->prompt),
            outputTokens: mb_strlen($text),
            latencyMs: 1,
        );
    }

    /** @return list<ModelRequest> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastRequest(): ModelRequest
    {
        return $this->sent[array_key_last($this->sent)]
            ?? throw new RuntimeException('No model request was made.');
    }

    public function callCount(): int
    {
        return count($this->sent);
    }
}
