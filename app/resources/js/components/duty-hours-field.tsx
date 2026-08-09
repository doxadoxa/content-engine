import { Plus, X } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * The stored shape of `projects.duty_hours`: day key to a list of local
 * `["HH:MM", "HH:MM"]` ranges. Days with no hours are absent rather than
 * present and empty, which is what the server writes back.
 */
export type DutyHoursValue = Record<string, string[][]>;

const DAYS = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
] as const;

const DEFAULT_RANGE = ['09:00', '18:00'];

type Props = {
    value: DutyHoursValue;
    onChange: (value: DutyHoursValue) => void;
    timezone?: string;
    error?: string;
};

/**
 * When somebody is around to answer (§4.3).
 *
 * Seven rows rather than a grid or a drag-to-select calendar: this is answered
 * once and edited rarely, and a row of two time inputs is the thing that works
 * on a phone, reads out sensibly to a screen reader, and needs no explanation.
 *
 * A stored end of `24:00` is legal in the column but not typeable in a time
 * input, so the browser renders that field blank. State is the source of truth
 * and keeps the value, so opening the form does not quietly erase it — only
 * editing that particular field does.
 */
export function DutyHoursField({ value, onChange, timezone, error }: Props) {
    const write = (day: string, ranges: string[][]) => {
        const next = { ...value };

        if (ranges.length === 0) {
            delete next[day];
        } else {
            next[day] = ranges;
        }

        onChange(next);
    };

    const setEdge = (day: string, index: number, edge: 0 | 1, time: string) => {
        const ranges = (value[day] ?? []).map((range, position) =>
            position === index
                ? edge === 0
                    ? [time, range[1] ?? '']
                    : [range[0] ?? '', time]
                : range,
        );

        write(day, ranges);
    };

    return (
        <fieldset className="grid gap-3">
            <legend className="text-sm leading-none font-medium">
                Hours somebody is around to reply
            </legend>

            <p className="text-sm text-muted-foreground">
                Social posts are only scheduled inside these hours, because
                replies in the first hour after a post are what decide how far
                it travels. Read in the project&rsquo;s own time zone
                {timezone ? ` (${timezone})` : ''}.{' '}
                <strong>
                    Leave this empty and nothing will ever be scheduled
                </strong>{' '}
                — an unanswered question must not look like round-the-clock
                cover.
            </p>

            <ul className="flex flex-col gap-2">
                {DAYS.map(([day, label]) => {
                    const ranges = value[day] ?? [];

                    return (
                        <li
                            key={day}
                            className="grid gap-2 rounded-xl border bg-background/30 p-3 sm:grid-cols-[7rem_minmax(0,1fr)] sm:items-start"
                        >
                            <span className="pt-1.5 text-sm font-medium">
                                {label}
                            </span>

                            <div className="flex flex-col gap-2">
                                {ranges.length === 0 && (
                                    <p className="pt-1.5 text-sm text-muted-foreground">
                                        Nobody on duty.
                                    </p>
                                )}

                                {ranges.map((range, index) => (
                                    <div
                                        // Ranges have no id and are reordered
                                        // by the server rather than here, so
                                        // position is the only stable handle.
                                        key={`${day}-${index}`}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <Label
                                            className="sr-only"
                                            htmlFor={`duty-${day}-${index}-from`}
                                        >
                                            {label} window {index + 1} starts
                                        </Label>
                                        <Input
                                            id={`duty-${day}-${index}-from`}
                                            type="time"
                                            className="w-32"
                                            value={range[0] ?? ''}
                                            onChange={(event) =>
                                                setEdge(
                                                    day,
                                                    index,
                                                    0,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <span
                                            aria-hidden="true"
                                            className="text-muted-foreground"
                                        >
                                            to
                                        </span>
                                        <Label
                                            className="sr-only"
                                            htmlFor={`duty-${day}-${index}-to`}
                                        >
                                            {label} window {index + 1} ends
                                        </Label>
                                        <Input
                                            id={`duty-${day}-${index}-to`}
                                            type="time"
                                            className="w-32"
                                            value={range[1] ?? ''}
                                            onChange={(event) =>
                                                setEdge(
                                                    day,
                                                    index,
                                                    1,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="rounded-full"
                                            aria-label={`Remove ${label} window ${index + 1}`}
                                            onClick={() =>
                                                write(
                                                    day,
                                                    ranges.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="self-start rounded-full"
                                    onClick={() =>
                                        write(day, [
                                            ...ranges,
                                            [...DEFAULT_RANGE],
                                        ])
                                    }
                                >
                                    <Plus className="size-4" aria-hidden />
                                    Add hours
                                </Button>
                            </div>
                        </li>
                    );
                })}
            </ul>

            <p className="text-xs text-muted-foreground">
                Hours that touch or overlap are saved as one window, and a range
                we cannot read is dropped — so what you see after saving is what
                the engine will use.
            </p>

            <InputError message={error} />
        </fieldset>
    );
}
