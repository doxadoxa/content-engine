<?php

declare(strict_types=1);

namespace App\Enums;

use App\Audit\AuditScore;

/**
 * How much a site audit finding costs.
 *
 * Three levels rather than five, because a level is only worth having if it
 * changes what an operator does next, and "fix this before publishing anything
 * else / fix this soon / fix this when you are here anyway" is the whole of
 * that. Each carries its own penalty, so {@see AuditScore} asks the severity
 * what it is worth instead of holding a table of magic numbers.
 */
enum AuditSeverity: string
{
    /** The page cannot be indexed, or is being indexed as something else. */
    case High = 'high';

    /** Indexable, but losing rankings or citations it should be getting. */
    case Medium = 'medium';

    /** Worth tidying. A page made only of these is a healthy page. */
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * Points off a page's hundred.
     *
     * Wide gaps on purpose. A page missing its title should not be rescuable by
     * having tidy alt text, and seven low findings should not outrank one
     * high one — which is what a 5/3/1 spread would do.
     */
    public function penalty(): int
    {
        return match ($this) {
            self::High => 20,
            self::Medium => 8,
            self::Low => 3,
        };
    }

    /**
     * Most severe first, for the screen and for the fix plan's prompt.
     *
     * @return list<self>
     */
    public static function ranked(): array
    {
        return [self::High, self::Medium, self::Low];
    }

    public function isAtLeast(self $floor): bool
    {
        return $this->penalty() >= $floor->penalty();
    }
}
