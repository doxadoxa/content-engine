import { Badge } from 'avyo';

export function Variants() {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Badge>Published</Badge>
            <Badge variant="secondary">Draft</Badge>
            <Badge variant="outline">Scheduled</Badge>
            <Badge variant="destructive">Failed</Badge>
        </div>
    );
}

/** Project status pills, as the projects index renders them. */
export function StatusPills() {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Badge className="rounded-full capitalize">active</Badge>
            <Badge variant="secondary" className="rounded-full capitalize">
                paused
            </Badge>
            <Badge variant="outline" className="rounded-full capitalize">
                archived
            </Badge>
        </div>
    );
}

export function WithCount() {
    return (
        <div className="flex flex-wrap items-center gap-4 text-sm">
            <span className="inline-flex items-center gap-2">
                Approvals
                <Badge variant="secondary">7</Badge>
            </span>
            <span className="inline-flex items-center gap-2">
                Locales
                <Badge variant="outline">4</Badge>
            </span>
            <span className="inline-flex items-center gap-2">
                Errors
                <Badge variant="destructive">2</Badge>
            </span>
        </div>
    );
}
