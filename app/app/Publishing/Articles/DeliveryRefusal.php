<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Models\ArticleSchedule;
use App\Models\ContentItem;

/**
 * Why a delivery may not go out, from {@see ArticleDeliveryGuard::verdict()}.
 *
 * A refusal about approval carries the schedule and article it applies to,
 * so whoever dead-letters the delivery can hand the article back to its owner
 * in the same transaction ({@see ArticleSchedules::awaitOwner()}).
 */
final readonly class DeliveryRefusal
{
    public function __construct(
        public string $reason,
        public ?string $handBackCode = null,
        public ?ArticleSchedule $schedule = null,
        public ?ContentItem $item = null,
    ) {}

    /** Hand the article back to the owner, if this refusal is about approval. */
    public function handBack(): void
    {
        if ($this->handBackCode !== null && $this->schedule !== null && $this->item !== null) {
            app(ArticleSchedules::class)->awaitOwner($this->schedule, $this->item, $this->handBackCode, $this->reason);
        }
    }
}
