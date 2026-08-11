import { Button, Spinner } from 'avyo';

export function Sizes() {
    return (
        <div className="flex items-center gap-6">
            <Spinner />
            <Spinner className="size-6" />
            <Spinner className="size-8" />
            <Spinner className="size-10 text-muted-foreground" />
        </div>
    );
}

export function InContext() {
    return (
        <div className="flex flex-col items-start gap-5">
            <Button disabled>
                <Spinner />
                Publishing…
            </Button>
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner />
                Checking the feed for new posts
            </p>
        </div>
    );
}
