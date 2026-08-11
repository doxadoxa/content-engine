import { Card, CardContent, CardHeader, Skeleton } from 'avyo';

export function Shapes() {
    return (
        <div className="flex flex-col gap-4">
            <Skeleton className="h-4 w-64" />
            <Skeleton className="h-4 w-48" />
            <Skeleton className="size-12 rounded-full" />
            <Skeleton className="h-24 w-64 rounded-xl" />
        </div>
    );
}

/** What a project card looks like while its counts are still loading. */
export function LoadingCard() {
    return (
        <Card className="max-w-md">
            <CardHeader className="flex-row items-center gap-3">
                <Skeleton className="size-11 rounded-2xl" />
                <div className="grid gap-2">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-3 w-20" />
                </div>
            </CardHeader>
            <CardContent className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-6 w-12" />
                </div>
                <div className="grid gap-2">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-6 w-12" />
                </div>
            </CardContent>
        </Card>
    );
}
