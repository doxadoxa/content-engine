<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Pipelines\Contracts\StepPayload;
use App\Social\GovernorVerdict;

/**
 * What the week actually got, and every reason it did not get more (§4.3, §7).
 *
 * `refusals` is not diagnostics. §7 makes the explanation mandatory — "чего
 * движок делать не стал и почему… Молчащий автомат неотличим от сломанного" —
 * so a run that plans nothing must still hand back the sentences that say why,
 * and `slotIds` being empty with `refusals` empty too would be the one shape
 * this pipeline is not allowed to produce.
 *
 * The verdict travels with them, advanced by everything that was placed. That
 * is what makes the floor a fact about the finished week: {@see RecordPlan}
 * reads the counters as they ended up, rather than re-deriving them from a
 * count and getting the pre-existing units of the week wrong.
 */
final readonly class PlannedSlotsPayload implements StepPayload
{
    /**
     * @param  list<string>  $slotIds  `content_items` rows now holding a slot
     * @param  list<string>  $refusals  one sentence each, in the order decided
     */
    public function __construct(
        public GovernorVerdict $verdict,
        public array $slotIds,
        public array $refusals = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->toArray(),
            'slot_ids' => $this->slotIds,
            'refusals' => $this->refusals,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        /** @var array<string, mixed> $verdict */
        $verdict = is_array($data['verdict'] ?? null) ? $data['verdict'] : [];

        return new self(
            verdict: GovernorVerdict::fromArray($verdict),
            slotIds: array_values(array_map(
                strval(...),
                is_array($data['slot_ids'] ?? null) ? $data['slot_ids'] : [],
            )),
            refusals: array_values(array_map(
                strval(...),
                is_array($data['refusals'] ?? null) ? $data['refusals'] : [],
            )),
        );
    }
}
