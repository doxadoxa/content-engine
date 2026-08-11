import { PlaceholderPattern } from 'avyo';

/**
 * A diagonal hatch drawn as an SVG `<pattern>`. It has no size and no colour
 * of its own — the `<path>` is stroked by whatever `stroke-*` utility the
 * className carries, and the `<svg>` fills whatever box you give it. A bare
 * `<PlaceholderPattern />` is therefore invisible by construction, which is
 * exactly what these cells exist to show the right version of.
 */
export function Default() {
    return (
        <div className="relative h-40 w-full max-w-md overflow-hidden rounded-xl border">
            <PlaceholderPattern className="absolute inset-0 size-full stroke-border" />
        </div>
    );
}

export function EmptyState() {
    return (
        <div className="relative flex h-48 w-full max-w-md flex-col items-center justify-center gap-2 overflow-hidden rounded-xl border">
            <PlaceholderPattern className="absolute inset-0 size-full stroke-border/70" />
            <p className="relative text-sm font-medium">No drafts yet</p>
            <p className="relative text-sm text-muted-foreground">
                The next run is scheduled for Monday at 06:00.
            </p>
        </div>
    );
}

export function Tones() {
    return (
        <div className="grid w-full max-w-md grid-cols-3 gap-4">
            {[
                'stroke-border',
                'stroke-chart-1/40',
                'stroke-chart-2/40',
            ].map((tone) => (
                <div
                    key={tone}
                    className="relative h-24 overflow-hidden rounded-lg border"
                >
                    <PlaceholderPattern
                        className={`absolute inset-0 size-full ${tone}`}
                    />
                </div>
            ))}
        </div>
    );
}
