<?php

declare(strict_types=1);

namespace App\Social;

use Illuminate\Support\Carbon;

/**
 * What the guard made of one reply draft — the document behind
 * `interactions.draft_guard`.
 *
 * Stored rather than recomputed for display, because §7's requirement is that
 * the operator sees *why* on the row. Recomputed rather than trusted at the
 * moment of sending, because the operator may have rewritten the text since:
 * {@see ReplyGuard} runs again over whatever is actually being sent, and only
 * the fact-check finding — which cost a model call and cannot be redone inside
 * a request — is carried forward from here.
 */
final readonly class ReplyGuardVerdict
{
    /**
     * @param  list<ReplyGuardFinding>  $findings
     */
    public function __construct(
        public array $findings = [],
        public ?Carbon $checkedAt = null,
    ) {}

    /** Nothing to say about the draft at all. */
    public function passed(): bool
    {
        return $this->findings === [];
    }

    /**
     * Whether a person may put this text into the thread as it stands.
     *
     * False does not mean "throw it away". It means the Send button is off and
     * the reason is on the row — the operator edits, skips, or leaves it.
     */
    public function sendable(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->blocking) {
                return false;
            }
        }

        return true;
    }

    public function finding(string $code): ?ReplyGuardFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }

    /** This verdict plus one more finding. */
    public function with(?ReplyGuardFinding $finding): self
    {
        if ($finding === null) {
            return $this;
        }

        return new self([...$this->findings, $finding], $this->checkedAt);
    }

    /** The first reason a person cannot send this, for a flash message. */
    public function firstBlocking(): ?string
    {
        foreach ($this->findings as $finding) {
            if ($finding->blocking) {
                return $finding->detail;
            }
        }

        return null;
    }

    /**
     * @return array{findings: list<array{code: string, detail: string, blocking: bool}>, checked_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'findings' => array_map(
                static fn (ReplyGuardFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'checked_at' => ($this->checkedAt ?? Carbon::now())->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $rows = $stored['findings'] ?? null;
        $findings = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    $findings[] = ReplyGuardFinding::fromArray($row);
                }
            }
        }

        $checkedAt = $stored['checked_at'] ?? null;

        return new self(
            $findings,
            is_string($checkedAt) && $checkedAt !== '' ? Carbon::parse($checkedAt) : null,
        );
    }
}
