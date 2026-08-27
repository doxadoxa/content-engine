import { Form, Link } from '@inertiajs/react';
import { AlertTriangle, Check, Link2, Unlink } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { workspacePanelClass } from '@/components/workspace-page';
import { connect, disconnect } from '@/routes/threads';

export type ThreadsPanel =
    | { state: 'unavailable'; reason: string }
    | { state: 'disconnected' }
    | {
          state: 'broken';
          reason: string | null;
          connected_at: string | null;
          username: string | null;
      }
    | {
          state: 'connected';
          username: string | null;
          user_id: string | null;
          connected_at: string | null;
          connected_by: string | null;
          last_synced_at: string | null;
          expires_at: string | null;
          grants_keyword_search: boolean;
      };

/**
 * Connecting one project to one Threads account.
 *
 * One grant, two contours: the same token publishes posts and hears the
 * replies. Per project for the same reason the Google panel is — two projects
 * are two different brands, and one account in the environment would post one
 * of them under the other's name.
 */
export function ThreadsConnection({
    projectId,
    threads,
}: {
    projectId: string;
    threads?: ThreadsPanel;
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="text-base">Threads</CardTitle>
                <CardDescription>
                    Connect Threads so Avyo can publish posts and learn from the
                    questions and language your audience uses.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {threads && <Panel projectId={projectId} threads={threads} />}
            </CardContent>
        </Card>
    );
}

function Panel({
    projectId,
    threads,
}: {
    projectId: string;
    threads: ThreadsPanel;
}) {
    /* No app on this installation. Saying so is the whole of what this state
       does: a Connect button here would send the operator to a consent screen
       that cannot exist. */
    if (threads.state === 'unavailable') {
        return (
            <p className="text-sm text-muted-foreground">
                {threads.reason} Add <code>THREADS_APP_ID</code> and{' '}
                <code>THREADS_APP_SECRET</code> to the environment to turn this
                on.
            </p>
        );
    }

    if (threads.state === 'disconnected') {
        return (
            <Button className="rounded-full" asChild>
                <Link href={connect(projectId)}>
                    <Link2 className="size-4" aria-hidden="true" />
                    Connect Threads
                </Link>
            </Button>
        );
    }

    if (threads.state === 'broken') {
        return (
            <div className="flex flex-col items-start gap-3">
                <p className="flex items-start gap-2 text-sm">
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0 text-amber-600"
                        aria-hidden="true"
                    />
                    <span>
                        {threads.reason ??
                            'Threads is no longer accepting this connection.'}{' '}
                        Nothing has been posted or heard since it broke.
                    </span>
                </p>
                <Button className="rounded-full" asChild>
                    <Link href={connect(projectId)}>Reconnect</Link>
                </Button>
            </div>
        );
    }

    return <Connected projectId={projectId} threads={threads} />;
}

function Connected({
    projectId,
    threads,
}: {
    projectId: string;
    threads: Extract<ThreadsPanel, { state: 'connected' }>;
}) {
    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <Badge variant="secondary" className="gap-1">
                    <Check className="size-3" aria-hidden="true" />
                    Connected
                </Badge>
                {threads.username && (
                    <span className="font-medium text-foreground">
                        @{threads.username}
                    </span>
                )}
                {threads.connected_by && <span>by {threads.connected_by}</span>}
            </div>

            {/* The date matters here in a way it does not for Google. A Threads
                token dies at about sixty days and is kept alive by a nightly
                renewal; printing when it runs out is how an operator can see
                that the renewal is happening rather than find out that it
                stopped. */}
            {threads.expires_at && (
                <p className="text-sm text-muted-foreground">
                    Access runs until{' '}
                    {new Date(threads.expires_at).toLocaleDateString()}, renewed
                    automatically before then.
                </p>
            )}

            {!threads.grants_keyword_search && (
                <p className="flex items-start gap-2 text-sm text-muted-foreground">
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0 text-amber-600"
                        aria-hidden="true"
                    />
                    <span>
                        Keyword search was not granted, so listening sees only
                        this account&rsquo;s own posts. Meta approves it
                        separately; reconnect once it has been.
                    </span>
                </p>
            )}

            <Form action={disconnect(projectId)} className="border-t pt-4">
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                    >
                        <Unlink className="size-4" aria-hidden="true" />
                        Disconnect
                    </Button>
                )}
            </Form>
        </div>
    );
}
