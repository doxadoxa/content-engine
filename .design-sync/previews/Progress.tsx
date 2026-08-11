import { Progress } from 'avyo';

export function Values() {
    return (
        <div className="flex max-w-md flex-col gap-5">
            {[0, 25, 60, 100].map((v) => (
                <div key={v} className="grid gap-2">
                    <div className="flex justify-between text-sm">
                        <span>Generating drafts</span>
                        <span className="text-muted-foreground">{v}%</span>
                    </div>
                    <Progress value={v} />
                </div>
            ))}
        </div>
    );
}

/**
 * `indicatorClassName` is this repo's addition to the stock component — it
 * lets a run's bar carry its status rather than every bar reading the same.
 */
export function StatusColours() {
    const runs = [
        { label: 'Completed', value: 100, tone: 'bg-chart-4' },
        { label: 'In progress', value: 45, tone: 'bg-chart-2' },
        { label: 'Failed', value: 72, tone: 'bg-destructive' },
    ];

    return (
        <div className="flex max-w-md flex-col gap-5">
            {runs.map(({ label, value, tone }) => (
                <div key={label} className="grid gap-2">
                    <div className="flex justify-between text-sm">
                        <span>{label}</span>
                        <span className="text-muted-foreground">{value}%</span>
                    </div>
                    <Progress value={value} indicatorClassName={tone} />
                </div>
            ))}
        </div>
    );
}
