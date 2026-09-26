import { useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';

export type TrendPoint = { day: string; value: number | null };

const WIDTH = 640;
const LEFT = 15;
const RIGHT = 625;
const TOP = 24;
const BOTTOM = 154;

const toDate = (day: string) =>
    new Date(day.length === 10 ? `${day}T12:00:00` : day);

/** Short day label, e.g. "3 Sept"; adds the year for long ranges. */
export const trendDate = (day: string, withYear = false) =>
    toDate(day).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(withYear ? { year: 'numeric' } : {}),
    });

/**
 * A single-series daily line with a snapping crosshair. Pointer users hover;
 * keyboard users focus the chart and move through days with the arrow keys.
 * Every value stays reachable without hovering through the caller's table view.
 */
export function TrendChart({
    points,
    unit,
    label,
    summary,
}: {
    points: TrendPoint[];
    /** Plural unit shown after each value, e.g. "clicks". */
    unit: string;
    /** Accessible name of the chart. */
    label: string;
    /** Plain-language summary read to screen readers. */
    summary?: string;
}) {
    const [active, setActive] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const svgRef = useRef<SVGSVGElement>(null);
    const summaryId = useId();
    const longRange =
        points.length > 1 &&
        toDate(points[points.length - 1].day).getTime() -
            toDate(points[0].day).getTime() >
            120 * 86_400_000;
    const max = Math.max(1, ...points.map((point) => point.value ?? 0));
    const y = (value: number) => BOTTOM - (value / max) * (BOTTOM - TOP);
    const x = (index: number) =>
        LEFT + (index / Math.max(1, points.length - 1)) * (RIGHT - LEFT);
    const path = points
        .map((point, index) => {
            if (point.value === null) {
                return '';
            }

            const command =
                index === 0 || points[index - 1].value === null ? 'M' : 'L';

            return `${command} ${x(index)} ${y(point.value)}`;
        })
        .join(' ');
    const showDots = points.length <= 35;
    const current = active === null ? null : points[active];
    const readout = (point: TrendPoint) =>
        `${trendDate(point.day, true)}: ${
            point.value === null
                ? 'no data'
                : `${point.value.toLocaleString()} ${unit}`
        }`;

    const nearest = (event: PointerEvent<HTMLDivElement>) => {
        const box = svgRef.current?.getBoundingClientRect();

        if (!box || points.length === 0) {
            return;
        }

        const svgX = ((event.clientX - box.left) / box.width) * WIDTH;
        const ratio = (svgX - LEFT) / (RIGHT - LEFT);

        setActive(
            Math.min(
                points.length - 1,
                Math.max(0, Math.round(ratio * (points.length - 1))),
            ),
        );
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (points.length === 0) {
            return;
        }

        const last = points.length - 1;
        const moves: Record<string, number> = {
            ArrowLeft: Math.max(0, (active ?? last + 1) - 1),
            ArrowRight: Math.min(last, (active ?? -1) + 1),
            Home: 0,
            End: last,
        };

        if (event.key in moves) {
            event.preventDefault();
            setActive(moves[event.key]);
            setAnnouncement(readout(points[moves[event.key]]));
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    return (
        <div>
            <div className="text-right text-xs text-muted-foreground tabular-nums">
                {max.toLocaleString()}
            </div>
            <div
                role="group"
                tabIndex={0}
                aria-label={`${label}. Use the left and right arrow keys to read each day.`}
                aria-describedby={summary ? summaryId : undefined}
                onPointerMove={nearest}
                onPointerLeave={() => setActive(null)}
                onKeyDown={onKeyDown}
                onBlur={() => {
                    setActive(null);
                    setAnnouncement('');
                }}
                className="relative rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
            >
                <svg
                    ref={svgRef}
                    viewBox={`0 0 ${WIDTH} 175`}
                    className="w-full touch-pan-y"
                    aria-hidden="true"
                >
                    {[TOP, (TOP + BOTTOM) / 2, BOTTOM].map((height) => (
                        <line
                            key={height}
                            x1={LEFT}
                            x2={RIGHT}
                            y1={height}
                            y2={height}
                            stroke="currentColor"
                            className="text-border"
                            strokeDasharray={
                                height === BOTTOM ? undefined : '4 5'
                            }
                        />
                    ))}
                    <path
                        d={path}
                        fill="none"
                        stroke="currentColor"
                        className="text-primary"
                        strokeWidth={showDots ? 3 : 2}
                        strokeLinejoin="round"
                        strokeLinecap="round"
                    />
                    {showDots &&
                        points.map(
                            (point, index) =>
                                point.value !== null && (
                                    <circle
                                        key={point.day}
                                        cx={x(index)}
                                        cy={y(point.value)}
                                        r="3"
                                        fill="currentColor"
                                        className="text-primary"
                                    />
                                ),
                        )}
                    {current && active !== null && (
                        <>
                            <line
                                x1={x(active)}
                                x2={x(active)}
                                y1={TOP - 8}
                                y2={BOTTOM}
                                stroke="currentColor"
                                className="text-muted-foreground"
                                strokeWidth="1"
                            />
                            {current.value !== null && (
                                <circle
                                    cx={x(active)}
                                    cy={y(current.value)}
                                    r="5"
                                    fill="currentColor"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    className="[stroke:var(--card)] text-primary"
                                />
                            )}
                        </>
                    )}
                </svg>
                {current && active !== null && (
                    <div
                        className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg border bg-popover px-2.5 py-1.5 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
                        style={{
                            left: `${Math.min(88, Math.max(12, (x(active) / WIDTH) * 100))}%`,
                        }}
                        aria-hidden="true"
                    >
                        <span className="font-semibold tabular-nums">
                            {current.value === null
                                ? 'No data'
                                : current.value.toLocaleString()}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {current.value === null ? '' : `${unit} · `}
                            {trendDate(current.day, longRange)}
                        </span>
                    </div>
                )}
            </div>
            <div className="flex justify-between text-xs text-muted-foreground">
                {points.length > 0 && (
                    <>
                        <span>{trendDate(points[0].day, longRange)}</span>
                        <span>
                            {trendDate(
                                points[points.length - 1].day,
                                longRange,
                            )}
                        </span>
                    </>
                )}
            </div>
            {summary && (
                <p id={summaryId} className="sr-only">
                    {summary}
                </p>
            )}
            <p role="status" className="sr-only">
                {announcement}
            </p>
        </div>
    );
}
