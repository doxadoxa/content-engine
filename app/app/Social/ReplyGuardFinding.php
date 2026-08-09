<?php

declare(strict_types=1);

namespace App\Social;

/**
 * One thing wrong with a reply draft, in words an operator can act on.
 *
 * §7 makes the explanation next to the silence mandatory — "чего движок делать
 * не стал и почему" — so a finding is never a boolean. It carries the code the
 * screen groups on, the sentence the operator reads, and whether it stops the
 * reply going out at all.
 *
 * **Blocking is not the same as bad.** A draft over the platform's 500
 * characters cannot be sent by anyone: Threads refuses it, so offering a Send
 * button would be offering a failure. A draft that touches a topic the Brand
 * Brief forbids is a judgement, and §4.2 already puts a human in this loop — so
 * it blocks the one-tap path and is undone by the operator editing the words,
 * which is exactly the decision the human is here to make.
 */
final readonly class ReplyGuardFinding
{
    public function __construct(
        public string $code,
        public string $detail,
        public bool $blocking,
    ) {}

    /**
     * The same finding, blocking or not.
     *
     * The fact-check verdict needs this. It is decided once, while the draft is
     * being written, and it means something different by the time the operator
     * has rewritten the sentence it was about — see {@see ReplyGuard::FACTCHECK}.
     */
    public function blocking(bool $blocking): self
    {
        return new self($this->code, $this->detail, $blocking);
    }

    /**
     * @return array{code: string, detail: string, blocking: bool}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'detail' => $this->detail, 'blocking' => $this->blocking];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            code: is_string($row['code'] ?? null) ? $row['code'] : 'unknown',
            detail: is_string($row['detail'] ?? null) ? $row['detail'] : '',
            blocking: (bool) ($row['blocking'] ?? false),
        );
    }
}
