import { Form, router } from '@inertiajs/react';
import {
    CheckCircle2,
    CircleDashed,
    Loader2,
    PauseCircle,
    Pencil,
    Play,
    Pause,
    Send,
    Trash2,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageUpdatesField } from '@/components/website/connect-website-form';
import { DeveloperPanel } from '@/components/website/developer-panel';
import type {
    ConnectionHealth,
    WebsiteConnection,
} from '@/components/website/types';
import {
    formatCheckedAt,
    wordPressApiBase,
    wordPressSiteAddress,
} from '@/components/website/types';
import { workspacePanelClass } from '@/components/workspace-page';
import { cn } from '@/lib/utils';
import { destroy, ping, update } from '@/routes/channels';

/**
 * One website: whether it works, what to do about it, and — for a webhook —
 * what the developer needs.
 */
export function WebsiteConnectionCard({
    connection,
    isOwner,
}: {
    connection: WebsiteConnection;
    isOwner: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const isWebhook = connection.type === 'webhook';

    return (
        <article
            aria-labelledby={`website-${connection.id}-name`}
            className={`${workspacePanelClass} flex flex-col gap-5 p-5 sm:p-7`}
        >
            <header className="flex min-w-0 flex-col gap-1">
                <h2
                    id={`website-${connection.id}-name`}
                    className="text-lg font-semibold tracking-[-0.02em] break-words"
                >
                    {connection.name}
                </h2>
                <p className="text-sm break-all text-muted-foreground">
                    {connection.type_label}
                    {connection.target ? ` · ${connection.target}` : ''}
                </p>
            </header>

            <HealthStatus health={connection.health} />

            {isOwner && (
                <ConnectionActions
                    connection={connection}
                    editing={editing}
                    onEdit={() => setEditing((value) => !value)}
                />
            )}

            {isOwner && editing && (
                <EditConnection
                    connection={connection}
                    onDone={() => setEditing(false)}
                />
            )}

            {isWebhook && (
                <DeveloperPanel connection={connection} isOwner={isOwner} />
            )}
        </article>
    );
}

const STATUS: Record<
    ConnectionHealth['state'],
    { icon: LucideIcon; tone: string; spin?: boolean }
> = {
    connected: {
        icon: CheckCircle2,
        tone: 'border-emerald-600/25 bg-emerald-600/5 text-emerald-700 dark:text-emerald-400',
    },
    testing: {
        icon: Loader2,
        tone: 'border-border bg-muted/40 text-foreground',
        spin: true,
    },
    failed: {
        icon: XCircle,
        tone: 'border-red-600/25 bg-red-600/5 text-red-700 dark:text-red-400',
    },
    untested: {
        icon: CircleDashed,
        tone: 'border-border bg-muted/40 text-foreground',
    },
    paused: {
        icon: PauseCircle,
        tone: 'border-border bg-muted/40 text-foreground',
    },
};

/** The big answer to "does it work?", announced when it changes. */
function HealthStatus({ health }: { health: ConnectionHealth }) {
    const { icon: Icon, tone, spin } = STATUS[health.state];
    const checkedAt = formatCheckedAt(health.checked_at);

    return (
        <div
            role="status"
            aria-live="polite"
            className={cn('flex gap-3 rounded-xl border p-4', tone)}
        >
            <Icon
                className={cn(
                    'mt-0.5 size-6 shrink-0',
                    spin && 'motion-safe:animate-spin',
                )}
                aria-hidden="true"
            />
            <div className="min-w-0">
                <p className="text-base font-semibold">
                    {health.headline}
                    {health.state === 'connected' && checkedAt && (
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            · last checked {checkedAt}
                        </span>
                    )}
                </p>
                {health.detail && (
                    <p className="mt-1 text-sm text-foreground/80">
                        {health.detail}
                    </p>
                )}
                {health.state === 'failed' && (
                    <p className="mt-1 text-sm text-foreground/80">
                        Fix it on your website, then test again.
                        {checkedAt && (
                            <span className="text-muted-foreground">
                                {' '}
                                Tried {checkedAt}.
                            </span>
                        )}
                    </p>
                )}
            </div>
        </div>
    );
}

function ConnectionActions({
    connection,
    editing,
    onEdit,
}: {
    connection: WebsiteConnection;
    editing: boolean;
    onEdit: () => void;
}) {
    const testing = connection.health.state === 'testing';
    const tested =
        connection.verified_at !== null || connection.health.state === 'failed';

    return (
        <div className="flex flex-wrap items-center gap-2">
            {connection.can_test && connection.is_enabled && (
                <Form
                    action={ping(connection.id).url}
                    method="post"
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            className="rounded-full"
                            disabled={processing || testing}
                        >
                            <Send className="size-4" aria-hidden="true" />
                            {testing
                                ? 'Testing…'
                                : tested
                                  ? 'Test again'
                                  : 'Send a test'}
                        </Button>
                    )}
                </Form>
            )}
            {['webhook', 'wordpress'].includes(connection.type) && (
                <Button
                    type="button"
                    variant="outline"
                    className="rounded-full"
                    aria-expanded={editing}
                    onClick={onEdit}
                >
                    <Pencil className="size-4" aria-hidden="true" />
                    {connection.type === 'wordpress'
                        ? 'Edit details'
                        : 'Edit address'}
                </Button>
            )}
            <Form
                action={update(connection.id).url}
                method="patch"
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="is_enabled"
                            value={connection.is_enabled ? '0' : '1'}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="rounded-full"
                            disabled={processing}
                        >
                            {connection.is_enabled ? (
                                <Pause className="size-4" aria-hidden="true" />
                            ) : (
                                <Play className="size-4" aria-hidden="true" />
                            )}
                            {connection.is_enabled ? 'Pause' : 'Resume'}
                        </Button>
                    </>
                )}
            </Form>
            <RemoveConnection connection={connection} />
        </div>
    );
}

function RemoveConnection({ connection }: { connection: WebsiteConnection }) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                className="rounded-full text-muted-foreground"
                onClick={() => {
                    setError(null);
                    setConfirming(true);
                }}
            >
                <Trash2 className="size-4" aria-hidden="true" />
                Remove
            </Button>
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="rounded-[1.5rem]">
                    <DialogHeader>
                        <DialogTitle>Remove {connection.name}?</DialogTitle>
                        <DialogDescription>
                            Avyo stops sending articles to this website, and its
                            delivery history is deleted. Articles already on
                            your site stay there.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={error ?? undefined} role="alert" />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing}
                            onClick={() =>
                                router.delete(destroy(connection.id).url, {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: () => setConfirming(false),
                                    onError: (errors) =>
                                        setError(
                                            errors.channel ??
                                                Object.values(errors)[0] ??
                                                'Unable to remove the website. Try again.',
                                        ),
                                })
                            }
                        >
                            Remove website
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Change where articles go. Saving tests the new details straight away. */
function EditConnection({
    connection,
    onDone,
}: {
    connection: WebsiteConnection;
    onDone: () => void;
}) {
    const id = connection.id;
    const isWordPress = connection.type === 'wordpress';
    const [apiBase, setApiBase] = useState(
        connection.native_config.page_receiver_base,
    );

    return (
        <Form
            action={update(id).url}
            method="patch"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="flex flex-col gap-5 rounded-xl border p-4 sm:p-5"
        >
            {({ processing, errors }) => (
                <>
                    {isWordPress ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor={`edit-${id}-site`}>
                                    WordPress site address
                                </Label>
                                <Input
                                    id={`edit-${id}-site`}
                                    type="url"
                                    inputMode="url"
                                    defaultValue={wordPressSiteAddress(
                                        connection.native_config
                                            .page_receiver_base,
                                    )}
                                    onChange={(event) =>
                                        setApiBase(
                                            wordPressApiBase(
                                                event.target.value,
                                            ),
                                        )
                                    }
                                />
                                <input
                                    type="hidden"
                                    name="config[page_receiver_base]"
                                    value={apiBase}
                                />
                                <InputError
                                    message={
                                        errors['config.page_receiver_base']
                                    }
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`edit-${id}-username`}>
                                    WordPress username
                                </Label>
                                <Input
                                    id={`edit-${id}-username`}
                                    name="config[username]"
                                    autoComplete="off"
                                    required
                                    defaultValue={
                                        connection.native_config.username
                                    }
                                />
                                <InputError
                                    message={errors['config.username']}
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`edit-${id}-password`}>
                                    New application password
                                </Label>
                                <Input
                                    id={`edit-${id}-password`}
                                    name="secret"
                                    type="password"
                                    autoComplete="new-password"
                                    aria-describedby={`edit-${id}-password-hint`}
                                />
                                <p
                                    id={`edit-${id}-password-hint`}
                                    className="text-xs text-muted-foreground"
                                >
                                    Leave empty to keep the current one.
                                </p>
                                <InputError message={errors.secret} />
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor={`edit-${id}-endpoint`}>
                                Webhook address
                            </Label>
                            <Input
                                id={`edit-${id}-endpoint`}
                                name="config[endpoint]"
                                type="url"
                                inputMode="url"
                                required={
                                    connection.type === 'webhook' &&
                                    connection.native_config
                                        .page_receiver_base === ''
                                }
                                placeholder="https://example.com/avyo/webhook"
                                defaultValue={connection.native_config.endpoint}
                            />
                            <InputError message={errors['config.endpoint']} />
                        </div>
                    )}

                    <details className="text-sm">
                        <summary className="w-fit cursor-pointer rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            More options
                        </summary>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`edit-${id}-name`}>Name</Label>
                                <Input
                                    id={`edit-${id}-name`}
                                    name="name"
                                    required
                                    defaultValue={connection.name}
                                />
                                <InputError message={errors.name} />
                            </div>
                            {connection.type === 'webhook' && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor={`edit-${id}-secret`}>
                                        Use your own secret
                                    </Label>
                                    <Input
                                        id={`edit-${id}-secret`}
                                        name="secret"
                                        type="password"
                                        autoComplete="new-password"
                                        aria-describedby={`edit-${id}-secret-hint`}
                                    />
                                    <p
                                        id={`edit-${id}-secret-hint`}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Leave empty to keep the current one.
                                    </p>
                                    <InputError message={errors.secret} />
                                </div>
                            )}
                        </div>
                    </details>

                    {connection.type === 'webhook' && (
                        <div className="-mt-2 flex flex-col gap-2">
                            <PageUpdatesField
                                idPrefix={`edit-${id}`}
                                defaultValue={
                                    connection.native_config.page_receiver_base
                                }
                            />
                            <InputError
                                message={errors['config.page_receiver_base']}
                            />
                        </div>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            className="rounded-full px-5"
                            disabled={processing}
                        >
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="rounded-full"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
