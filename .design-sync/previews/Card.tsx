import { ArrowUpRight, FolderKanban, Globe2 } from 'lucide-react';
import {
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from 'avyo';

export function Basic() {
    return (
        <Card className="max-w-md">
            <CardHeader>
                <CardTitle>Weekly content plan</CardTitle>
                <CardDescription>
                    Twelve briefs queued across three locales, ready for review
                    before Monday.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p className="text-sm text-muted-foreground">
                    Drafts are generated the evening before and wait in the
                    approvals queue until an editor signs them off.
                </p>
            </CardContent>
            <CardFooter className="gap-3">
                <Button size="sm">Review queue</Button>
                <Button size="sm" variant="ghost">
                    Skip this week
                </Button>
            </CardFooter>
        </Card>
    );
}

/** The project tile from the projects index, minus its router link. */
export function ProjectTile() {
    return (
        <Card className="max-w-md gap-5 overflow-hidden py-0">
            <CardHeader className="flex-row items-start justify-between gap-4 border-b px-5 py-5">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-sm font-semibold text-accent-foreground">
                        AV
                    </span>
                    <div className="min-w-0">
                        <h2 className="truncate text-lg font-semibold tracking-tight">
                            Avyo Blog
                        </h2>
                        <p className="truncate text-xs text-muted-foreground">
                            avyo-blog
                        </p>
                    </div>
                </div>
                <Badge className="rounded-full capitalize">active</Badge>
            </CardHeader>
            <CardContent className="px-5 pb-5">
                <dl className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Published
                        </dt>
                        <dd className="text-lg font-semibold">128</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            In review
                        </dt>
                        <dd className="text-lg font-semibold">7</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    );
}

export function Stats() {
    const stats = [
        { label: 'Articles live', value: '1,284', icon: FolderKanban },
        { label: 'Locales', value: '4', icon: Globe2 },
        { label: 'Impressions, 28d', value: '96.2k', icon: ArrowUpRight },
    ];

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            {stats.map(({ label, value, icon: Glyph }) => (
                <Card key={label}>
                    <CardHeader>
                        <CardDescription className="flex items-center gap-2">
                            <Glyph className="size-4" />
                            {label}
                        </CardDescription>
                        <CardTitle className="text-3xl">{value}</CardTitle>
                    </CardHeader>
                </Card>
            ))}
        </div>
    );
}
