<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

/**
 * One reason a post may not be published, in words an operator reads (§4.3, §7).
 *
 * A code and a sentence rather than a boolean, because both consumers are real:
 * the code is what §7's summary groups by when it asks which rule refuses most
 * often, and the sentence is what it shows the person reading it. §7 makes the
 * explanation mandatory — "Молчащий автомат неотличим от сломанного" — and a
 * refusal reconstructed at render time from an enum is a refusal that gets
 * rendered as "no".
 *
 * Every finding here is blocking. Unlike the reply guard of §4.2, which shows an
 * operator what is wrong and lets them send anyway, this one runs with nobody
 * watching: a slot either produces a draft or produces an empty slot with a
 * reason, so an advisory finding would have nowhere to be advisory to.
 */
final readonly class GuardFinding
{
    /** Longer than the platform will accept (§2: 500 characters). */
    public const string LENGTH = 'segment_length';

    /** More segments than a chain may have (§2: a chain is the exception). */
    public const string SEGMENT_COUNT = 'segment_count';

    /** A chain with no stated reason to be one (§2). */
    public const string UNJUSTIFIED_CHAIN = 'unjustified_chain';

    /** A topic the Brand Brief says this project never writes about (§4.3). */
    public const string FORBIDDEN_TOPIC = 'forbidden_topic';

    /** «Голая ссылка» — a URL with no thought around it (§2). */
    public const string BARE_LINK = 'bare_link';

    /** Something in the link policy other than bareness. */
    public const string LINK_POLICY = 'link_policy';

    /** A native post that resolves to none of the project's entities (§4.3). */
    public const string UNRESOLVED_ENTITY = 'unresolved_entity';

    /** A derivative that kept too little of its parent (§4.3, ≥34%). */
    public const string DERIVATIVE_OVERLAP = 'derivative_overlap';

    /** The model produced nothing. */
    public const string BLANK = 'blank';

    public function __construct(
        public string $code,
        public string $detail,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'detail' => $this->detail];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? ''),
            detail: (string) ($data['detail'] ?? ''),
        );
    }
}
