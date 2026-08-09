import { Deferred, Form, Link } from '@inertiajs/react';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { workspacePanelClass } from '@/components/workspace-page';
import { choose, connect, disconnect } from '@/routes/google';

type Option = { value: string; label: string };

export type GooglePanel =
    | { state: 'unavailable'; reason: string }
    | { state: 'disconnected' }
    | { state: 'broken'; reason: string | null; connected_at: string | null }
    | {
          state: 'connected';
          connected_at: string | null;
          connected_by: string | null;
          last_synced_at: string | null;
          grants_search_console: boolean;
          grants_analytics: boolean;
          search_console_site: string | null;
          analytics_property: string | null;
          sites: Option[];
          properties: Option[];
          listing_failed: boolean;
          suggested_site: string | null;
          suggested_property: string | null;
      };

/**
 * Connecting one project to one Google account.
 *
 * Per project rather than per installation: two projects are two different
 * people's websites, and one set of credentials in the environment would let
 * either one read the other's search data.
 */
export function GoogleConnection({
    projectId,
    google,
}: {
    projectId: string;
    google?: GooglePanel;
}) {
    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="text-base">
                    Search Console & Analytics
                </CardTitle>
                <CardDescription>
                    Connect Google and the engine can see what its articles
                    actually did — impressions, clicks, and whether people
                    stayed once they arrived.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Deferred data="google" fallback={() => <PanelSkeleton />}>
                    {google && <Panel projectId={projectId} google={google} />}
                </Deferred>
            </CardContent>
        </Card>
    );
}

function Panel({
    projectId,
    google,
}: {
    projectId: string;
    google: GooglePanel;
}) {
    if (google.state === 'unavailable') {
        return (
            <p className="text-sm text-muted-foreground">
                {google.reason} Add <code>GOOGLE_CLIENT_ID</code> and{' '}
                <code>GOOGLE_CLIENT_SECRET</code> to the environment to turn
                this on.
            </p>
        );
    }

    if (google.state === 'disconnected') {
        return (
            <Button className="rounded-full" asChild>
                <Link href={connect(projectId)}>
                    <Link2 className="size-4" aria-hidden="true" />
                    Connect Google
                </Link>
            </Button>
        );
    }

    if (google.state === 'broken') {
        return (
            <div className="flex flex-col items-start gap-3">
                <p className="flex items-start gap-2 text-sm">
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0 text-amber-600"
                        aria-hidden="true"
                    />
                    <span>
                        {google.reason ??
                            'Google is no longer accepting this connection.'}{' '}
                        Nothing has been collected since it broke.
                    </span>
                </p>
                <Button className="rounded-full" asChild>
                    <Link href={connect(projectId)}>Reconnect</Link>
                </Button>
            </div>
        );
    }

    return <Connected projectId={projectId} google={google} />;
}

function Connected({
    projectId,
    google,
}: {
    projectId: string;
    google: Extract<GooglePanel, { state: 'connected' }>;
}) {
    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <Badge variant="secondary" className="gap-1">
                    <Check className="size-3" aria-hidden="true" />
                    Connected
                </Badge>
                {google.connected_by && <span>by {google.connected_by}</span>}
                {google.last_synced_at && (
                    <span>
                        · last read{' '}
                        {new Date(google.last_synced_at).toLocaleDateString()}
                    </span>
                )}
            </div>

            {google.listing_failed && (
                <p className="text-sm text-muted-foreground">
                    We could not list your properties just now. The connection
                    is fine — reload to try again.
                </p>
            )}

            <Form
                action={choose(projectId)}
                className="flex flex-col gap-4"
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <>
                        <Choice
                            id="search_console_site"
                            label="Search Console property"
                            hint="Impressions, clicks and position, matched to each article by its URL."
                            granted={google.grants_search_console}
                            options={google.sites}
                            defaultValue={google.suggested_site}
                            missing="Search Console access was not granted. Reconnect and tick it."
                        />

                        <Choice
                            id="analytics_property"
                            label="Analytics property"
                            hint="Sessions and engagement — whether people stayed once they arrived."
                            granted={google.grants_analytics}
                            options={google.properties}
                            defaultValue={google.suggested_property}
                            missing="Analytics access was not granted. Reconnect and tick it."
                        />

                        <Button
                            type="submit"
                            disabled={processing}
                            className="self-start rounded-full"
                        >
                            Save
                        </Button>
                    </>
                )}
            </Form>

            {/* Outside the form above, not inside it: nested <form> elements
                are invalid HTML, and the browser resolves that by dropping the
                inner one — so Disconnect would submit the property selection
                instead of disconnecting. */}
            <DisconnectButton projectId={projectId} />
        </div>
    );
}

function Choice({
    id,
    label,
    hint,
    granted,
    options,
    defaultValue,
    missing,
}: {
    id: string;
    label: string;
    hint: string;
    granted: boolean;
    options: Option[];
    defaultValue: string | null;
    missing: string;
}) {
    if (!granted) {
        return (
            <div className="grid gap-1">
                <Label>{label}</Label>
                <p className="text-sm text-muted-foreground">{missing}</p>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select name={id} defaultValue={defaultValue ?? undefined}>
                <SelectTrigger id={id}>
                    <SelectValue
                        placeholder={
                            options.length === 0
                                ? 'Nothing available on this account'
                                : 'Choose one'
                        }
                    />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">{hint}</p>
        </div>
    );
}

function DisconnectButton({ projectId }: { projectId: string }) {
    return (
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
    );
}

function PanelSkeleton() {
    return (
        <div className="flex flex-col gap-3">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
        </div>
    );
}
