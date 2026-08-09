<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a draft was sent back (§7).
 *
 * A closed set rather than free text, because §7 makes the rejection the input
 * to phase 9's quality loop: "the tone is off" counted forty times across a
 * project is a signal about the brief, and the same sentence in a log is not
 * anything at all.
 */
enum RejectionReason: string
{
    /** Says something that is not true, or cannot be supported. */
    case Inaccurate = 'inaccurate';

    /** True, but does not sound like us — a brief problem. */
    case OffBrand = 'off_brand';

    /** Covers the wrong thing for the query it was written against. */
    case OffTopic = 'off_topic';

    /** Right subject, not enough substance. */
    case TooThin = 'too_thin';

    /** Needs a real price, case or local detail that nobody supplied. */
    case MissingData = 'missing_data';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Inaccurate => 'Inaccurate',
            self::OffBrand => 'Off brand',
            self::OffTopic => 'Off topic',
            self::TooThin => 'Too thin',
            self::MissingData => 'Missing business data',
            self::Other => 'Other',
        };
    }

    /**
     * Whether this reason points at the brief rather than at the draft.
     *
     * Phase 9 reads this: a project whose rejections are mostly off-brand has a
     * brief problem, and regenerating the same article will not fix it.
     */
    public function isBriefProblem(): bool
    {
        return $this === self::OffBrand;
    }
}
