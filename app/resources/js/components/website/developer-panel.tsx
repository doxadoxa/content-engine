import { Form } from '@inertiajs/react';
import { Check, ChevronDown, Copy, Eye, EyeOff, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { WebsiteConnection } from '@/components/website/types';
import { postJson } from '@/lib/json';
import { regenerate, reveal } from '@/routes/channels/secret';

/**
 * Everything a developer needs to receive articles, on one panel.
 *
 * Open until the first test passes, because until then this is the next
 * step; closed afterwards, because then it is reference. The secret is read
 * from the server on request and held only in this component's state.
 */
export function DeveloperPanel({
    connection,
    isOwner,
}: {
    connection: WebsiteConnection;
    isOwner: boolean;
}) {
    const [open, setOpen] = useState(connection.verified_at === null);
    // Bumped when the secret is replaced, so a revealed old one is dropped.
    const [secretVersion, setSecretVersion] = useState(0);
    const address = connection.native_config.endpoint;

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="rounded-xl border bg-background/40"
        >
            <CollapsibleTrigger className="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm font-medium hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                For your developer
                <ChevronDown
                    className={`size-4 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none ${open ? 'rotate-180' : ''}`}
                    aria-hidden="true"
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="flex flex-col gap-5 border-t px-4 pt-4 pb-5 text-sm">
                <dl className="grid gap-4">
                    <div className="grid gap-1.5">
                        <dt className="text-xs font-medium text-muted-foreground">
                            Webhook address
                        </dt>
                        <dd className="flex min-w-0 items-center gap-2">
                            <code className="min-w-0 flex-1 truncate rounded-md bg-muted px-2.5 py-2 font-mono text-xs">
                                {address || '—'}
                            </code>
                            {address && (
                                <CopyButton
                                    label="Copy webhook address"
                                    value={() => Promise.resolve(address)}
                                />
                            )}
                        </dd>
                    </div>
                    <div className="grid gap-1.5">
                        <dt className="text-xs font-medium text-muted-foreground">
                            Secret
                        </dt>
                        <dd>
                            {isOwner && connection.has_secret ? (
                                <SecretField
                                    key={secretVersion}
                                    connectionId={connection.id}
                                />
                            ) : (
                                <p className="text-muted-foreground">
                                    {connection.has_secret
                                        ? 'Only a project owner can see the secret.'
                                        : 'No secret yet. Create one below.'}
                                </p>
                            )}
                        </dd>
                    </div>
                </dl>

                <div>
                    <p className="font-medium">Your website needs to:</p>
                    <ol className="mt-2 grid list-decimal gap-2 pl-5 text-muted-foreground marker:text-muted-foreground">
                        <li>
                            Accept POST requests with a JSON body at the address
                            above.
                        </li>
                        <li>
                            Check the header{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                Authorization: Bearer &lt;secret&gt;
                            </code>
                            .
                        </li>
                        <li>
                            Check{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                X-Engine-Signature
                            </code>
                            . It is{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                sha256=
                            </code>{' '}
                            followed by the HMAC-SHA256, keyed with the secret,
                            of{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                {'{X-Engine-Timestamp}.{raw body}'}
                            </code>
                            . Reject timestamps more than 5 minutes old.
                        </li>
                        <li>
                            Answer with a 2xx status and{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                {'{"public_url": "https://…"}'}
                            </code>
                            , the article's address on your site. Tests arrive
                            with{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                X-Engine-Event: ping
                            </code>{' '}
                            and only need the 2xx.
                        </li>
                        <li>
                            Publish each{' '}
                            <code className="rounded bg-muted px-1 font-mono text-xs text-foreground">
                                X-Engine-Delivery
                            </code>{' '}
                            once. Answering 409 to a repeat counts as success.
                        </li>
                    </ol>
                </div>

                {isOwner && (
                    <RegenerateSecret
                        connectionId={connection.id}
                        onRegenerated={() =>
                            setSecretVersion((version) => version + 1)
                        }
                    />
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function SecretField({ connectionId }: { connectionId: string }) {
    const [secret, setSecret] = useState<string | null>(null);
    const [shown, setShown] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    async function load(): Promise<string> {
        if (secret !== null) {
            return secret;
        }

        setLoading(true);
        const result = await postJson<{ secret: string }>(
            reveal(connectionId).url,
            {},
        );
        setLoading(false);

        if (!result.ok) {
            setError(`Unable to load the secret. ${result.message}`);

            throw new Error(result.message);
        }

        setError(null);
        setSecret(result.data.secret);

        return result.data.secret;
    }

    return (
        <div className="grid gap-1.5">
            <div className="flex min-w-0 items-center gap-2">
                <code
                    className="min-w-0 flex-1 truncate rounded-md bg-muted px-2.5 py-2 font-mono text-xs"
                    aria-label={shown ? 'Secret' : 'Secret, hidden'}
                >
                    {shown && secret !== null
                        ? secret
                        : '••••••••••••••••••••••••'}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-full"
                    disabled={loading}
                    aria-pressed={shown}
                    onClick={async () => {
                        if (shown) {
                            setShown(false);

                            return;
                        }

                        try {
                            await load();
                            setShown(true);
                        } catch {
                            // The message is already on screen.
                        }
                    }}
                >
                    {shown ? (
                        <EyeOff className="size-4" aria-hidden="true" />
                    ) : (
                        <Eye className="size-4" aria-hidden="true" />
                    )}
                    {shown ? 'Hide' : 'Reveal'}
                </Button>
                <CopyButton label="Copy secret" value={load} />
            </div>
            {error && (
                <p
                    role="alert"
                    className="text-sm text-red-600 dark:text-red-400"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

function CopyButton({
    label,
    value,
}: {
    label: string;
    value: () => Promise<string>;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="rounded-full"
            aria-label={label}
            onClick={async () => {
                try {
                    await navigator.clipboard.writeText(await value());
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 2000);
                } catch {
                    setCopied(false);
                }
            }}
        >
            {copied ? (
                <Check className="size-4" aria-hidden="true" />
            ) : (
                <Copy className="size-4" aria-hidden="true" />
            )}
            <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
        </Button>
    );
}

function RegenerateSecret({
    connectionId,
    onRegenerated,
}: {
    connectionId: string;
    onRegenerated: () => void;
}) {
    const [confirming, setConfirming] = useState(false);

    return (
        <div className="border-t pt-4">
            <Button
                type="button"
                variant="ghost"
                className="-ml-3 rounded-full text-muted-foreground"
                onClick={() => setConfirming(true)}
            >
                <RefreshCw className="size-4" aria-hidden="true" />
                Create a new secret
            </Button>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="rounded-[1.5rem]">
                    <DialogHeader>
                        <DialogTitle>Create a new secret?</DialogTitle>
                        <DialogDescription>
                            The current secret stops working at once. Your
                            website must be updated with the new one before it
                            accepts articles again. Avyo tests the connection
                            straight away.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        action={regenerate(connectionId).url}
                        method="post"
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            setConfirming(false);
                            onRegenerated();
                        }}
                    >
                        {({ processing }) => (
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setConfirming(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Create new secret
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
