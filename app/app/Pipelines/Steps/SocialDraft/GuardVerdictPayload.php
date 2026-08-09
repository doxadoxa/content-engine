<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Contracts\StepPayload;

/**
 * What the deterministic guard found, and what it resolved (§4.3).
 *
 * `entities` is not diagnostics. §4.3 makes resolution a gate — "сущности
 * обязаны быть в пространстве Brief или корпуса" — and the entities it resolved
 * on the way through are what {@see SaveDraft} writes onto the unit, so the post
 * joins the same entity cluster as the articles (§1.3: "два ребёнка одного
 * кластера сущностей"). Re-resolving them a step later would be the same work
 * twice with a chance of a different answer.
 */
final readonly class GuardVerdictPayload implements StepPayload
{
    /**
     * @param  list<GuardFinding>  $findings  empty means the post may be drafted
     * @param  list<string>  $entities  what the post resolved to in the project's space
     * @param  int|null  $overlapPercent  the parent overlap, for a derivative only
     */
    public function __construct(
        public array $findings,
        public array $entities,
        public ?int $overlapPercent = null,
    ) {}

    public function passed(): bool
    {
        return $this->findings === [];
    }

    /** Every refusal as one sentence, for §7's summary. */
    public function reason(): string
    {
        return implode(' ', array_map(
            static fn (GuardFinding $finding): string => $finding->detail,
            $this->findings,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'findings' => array_map(
                static fn (GuardFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'entities' => $this->entities,
            'overlap_percent' => $this->overlapPercent,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        $findings = [];

        foreach (is_array($data['findings'] ?? null) ? $data['findings'] : [] as $finding) {
            if (is_array($finding)) {
                $findings[] = GuardFinding::fromArray($finding);
            }
        }

        return new self(
            findings: $findings,
            entities: array_values(array_map(
                strval(...),
                is_array($data['entities'] ?? null) ? $data['entities'] : [],
            )),
            overlapPercent: isset($data['overlap_percent']) && is_numeric($data['overlap_percent'])
                ? (int) $data['overlap_percent']
                : null,
        );
    }
}
