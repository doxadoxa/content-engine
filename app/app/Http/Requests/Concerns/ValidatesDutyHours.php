<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Duty\DutyHours;

/**
 * The wire shape of `projects.duty_hours`, in the two requests that write it.
 *
 * Structure only. {@see DutyHours::fromArray()} is deliberately forgiving
 * about content — an unparseable time or an inverted range is dropped rather
 * than fatal — so validating the same things twice
 * would only mean rejecting payloads the value object already handles. What
 * validation is for here is the shape: a day-keyed object of lists of time
 * pairs. Anything else is a client that has gone wrong, and storing it would
 * put a column in the database that reads as "never on duty" for a reason
 * nobody can see.
 */
trait ValidatesDutyHours
{
    /**
     * @return array<string, list<string>>
     */
    protected static function dutyHoursRules(string $key): array
    {
        return [
            // Only the seven day keys, so a typo lands as an error on the
            // field rather than as a window that silently never applies.
            $key => ['sometimes', 'nullable', 'array:mon,tue,wed,thu,fri,sat,sun'],
            $key.'.*' => ['array', 'max:12'],
            $key.'.*.*' => ['array', 'max:2'],
            $key.'.*.*.*' => ['string', 'max:20'],
        ];
    }
}
