import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from 'avyo';

export function Default() {
    return (
        <Alert className="max-w-xl">
            <Info />
            <AlertTitle>Feed connected</AlertTitle>
            <AlertDescription>
                Avyo will check avyo.io/feed.xml every six hours and queue new
                posts for review.
            </AlertDescription>
        </Alert>
    );
}

export function Destructive() {
    return (
        <Alert variant="destructive" className="max-w-xl">
            <AlertTriangle />
            <AlertTitle>Publishing failed</AlertTitle>
            <AlertDescription>
                The Threads token expired three days ago. Reconnect the account
                to resume the queue.
            </AlertDescription>
        </Alert>
    );
}

export function TitleOnly() {
    return (
        <Alert className="max-w-xl">
            <CheckCircle2 />
            <AlertTitle>All twelve drafts approved.</AlertTitle>
        </Alert>
    );
}
