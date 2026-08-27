import { Form, Head, usePage, usePoll } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Plus, Radio, Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { autopublish, index, ping, store } from '@/routes/channels';

type ChannelRow = {
    id: string;
    name: string;
    type: string;
    type_label: string;
    is_social: boolean;
    is_enabled: boolean;
    /** Whether a secret is stored — never the secret itself. */
    has_secret: boolean;
    autopublish: boolean;
    verified_at: string | null;
    test_pending: boolean;
    target: string | null;
    created_at: string | null;
};

type ChannelTypeOption = { value: string; label: string; is_social: boolean };

type Props = {
    channels: ChannelRow[];
    types: ChannelTypeOption[];
};

export default function ChannelsIndex({ channels, types }: Props) {
    const { auth } = usePage().props;
    const isOwner = auth.project?.role === 'owner';
    const [connecting, setConnecting] = useState(false);

    const polling = usePoll(
        3000,
        { only: ['channels'] },
        {
            autoStart: false,
            mode: 'rest',
        },
    );
    const hasPendingTest = channels.some((channel) => channel.test_pending);

    useEffect(() => {
        if (hasPendingTest) {
            polling.start();
        } else {
            polling.stop();
        }

        return polling.stop;
    }, [hasPendingTest, polling]);

    return (
        <>
            <Head title="Channels" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Distribution"
                    context={`${channels.length} ${channels.length === 1 ? 'channel' : 'channels'}`}
                    title="Channels"
                    description="Where this project publishes and whether each destination is configured and verified."
                    actions={
                        isOwner ? (
                            <Button
                                className="rounded-full"
                                onClick={() => setConnecting(true)}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Connect a channel
                            </Button>
                        ) : undefined
                    }
                />

                {channels.length > 0 && <ChannelSummary channels={channels} />}

                {channels.length === 0 ? (
                    <EmptyChannels isOwner={isOwner} />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 sm:hidden">
                            {channels.map((channel) => (
                                <ChannelMobileCard
                                    key={channel.id}
                                    channel={channel}
                                    isOwner={isOwner}
                                />
                            ))}
                        </div>

                        <Card
                            className={`${workspacePanelClass} hidden max-w-full overflow-hidden p-0 sm:block`}
                        >
                            <Table className="table-fixed">
                                <TableHeader>
                                    <TableRow className="bg-muted/20 text-xs tracking-wide uppercase">
                                        <TableHead className="w-[13%]">
                                            Name
                                        </TableHead>
                                        <TableHead className="w-[11%]">
                                            Type
                                        </TableHead>
                                        <TableHead className="w-[22%]">
                                            Target
                                        </TableHead>
                                        <TableHead className="w-[11%]">
                                            Secret
                                        </TableHead>
                                        <TableHead className="w-[13%]">
                                            Connected
                                        </TableHead>
                                        <TableHead className="w-[12%]">
                                            Auto
                                        </TableHead>
                                        <TableHead className="w-[18%]">
                                            Status &amp; test
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {channels.map((channel) => (
                                        <TableRow key={channel.id}>
                                            <TableCell className="font-medium">
                                                {channel.name}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        channel.is_social
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                >
                                                    {channel.type_label}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate text-muted-foreground">
                                                {channel.target ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {channel.has_secret ? (
                                                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                                        <KeyRound
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        Stored
                                                    </span>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">
                                                        None
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {channel.test_pending ? (
                                                    <span
                                                        className="text-sm text-muted-foreground"
                                                        role="status"
                                                    >
                                                        Testing…
                                                    </span>
                                                ) : channel.verified_at ===
                                                  null ? (
                                                    <span className="text-sm text-muted-foreground">
                                                        Never
                                                    </span>
                                                ) : (
                                                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                                        <CheckCircle2
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {channel.verified_at.slice(
                                                            0,
                                                            10,
                                                        )}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {isOwner ? (
                                                    <Form
                                                        action={
                                                            autopublish(
                                                                channel.id,
                                                            ).url
                                                        }
                                                        method="patch"
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    processing ||
                                                                    channel.type !==
                                                                        'webhook' ||
                                                                    (!channel.autopublish &&
                                                                        channel.verified_at ===
                                                                            null)
                                                                }
                                                                title={
                                                                    channel.verified_at ===
                                                                    null
                                                                        ? 'Test the channel before enabling automatic publishing'
                                                                        : undefined
                                                                }
                                                            >
                                                                <Badge
                                                                    variant={
                                                                        channel.autopublish
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                >
                                                                    {channel.autopublish
                                                                        ? 'Automatic'
                                                                        : 'Manual'}
                                                                </Badge>
                                                            </Button>
                                                        )}
                                                    </Form>
                                                ) : (
                                                    <Badge
                                                        variant={
                                                            channel.autopublish
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {channel.autopublish
                                                            ? 'Automatic'
                                                            : 'Manual'}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col items-start gap-1">
                                                    <Badge
                                                        variant={
                                                            channel.is_enabled
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {channel.is_enabled
                                                            ? 'Enabled'
                                                            : 'Disabled'}
                                                    </Badge>
                                                    {isOwner &&
                                                        channel.type ===
                                                            'webhook' && (
                                                            <Form
                                                                action={
                                                                    ping(
                                                                        channel.id,
                                                                    ).url
                                                                }
                                                                method="post"
                                                                options={{
                                                                    preserveScroll: true,
                                                                }}
                                                            >
                                                                {({
                                                                    processing,
                                                                }) => (
                                                                    <Button
                                                                        type="submit"
                                                                        variant="ghost"
                                                                        size="sm"
                                                                        className="h-7 px-1.5 text-xs"
                                                                        disabled={
                                                                            processing ||
                                                                            channel.test_pending
                                                                        }
                                                                    >
                                                                        <Send
                                                                            className="size-4"
                                                                            aria-hidden="true"
                                                                        />
                                                                        {channel.test_pending
                                                                            ? 'Testing…'
                                                                            : 'Test'}
                                                                    </Button>
                                                                )}
                                                            </Form>
                                                        )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </>
                )}

                <div className="rounded-[1.25rem] border border-dashed bg-card/45 px-5 py-4 text-xs leading-relaxed text-muted-foreground">
                    <p>
                        Secrets are encrypted and never returned to this page.
                        Connected means a signed test delivery was answered.
                        Automatic channels receive approved work without a
                        second click; manual channels publish from the article.
                    </p>
                    {!isOwner && (
                        <p className="mt-2">
                            Configuration is read-only for operators. A project
                            owner must connect, test, or change a channel.
                        </p>
                    )}
                </div>
            </WorkspacePage>

            {isOwner && (
                <ConnectDialog
                    open={connecting}
                    types={types}
                    onClose={() => setConnecting(false)}
                />
            )}
        </>
    );
}

function ChannelSummary({ channels }: { channels: ChannelRow[] }) {
    const verified = channels.filter(
        (channel) => channel.verified_at !== null,
    ).length;
    const automatic = channels.filter((channel) => channel.autopublish).length;
    const enabled = channels.filter((channel) => channel.is_enabled).length;

    return (
        <section
            className={`${workspacePanelClass} grid grid-cols-3 divide-x px-2 py-4 text-center sm:px-4`}
            aria-label="Channel overview"
        >
            <ChannelMetric label="Enabled" value={enabled} />
            <ChannelMetric label="Verified" value={verified} />
            <ChannelMetric label="Automatic" value={automatic} />
        </section>
    );
}

function ChannelMetric({ label, value }: { label: string; value: number }) {
    return (
        <div className="px-2 sm:px-4">
            <p className="text-2xl font-semibold tabular-nums">{value}</p>
            <p className="mt-0.5 text-[10px] tracking-wide text-muted-foreground uppercase sm:text-xs">
                {label}
            </p>
        </div>
    );
}

function ChannelMobileCard({
    channel,
    isOwner,
}: {
    channel: ChannelRow;
    isOwner: boolean;
}) {
    return (
        <Card className={`${workspacePanelClass} min-w-0 gap-4 py-5`}>
            <CardHeader className="flex-row items-start justify-between gap-3 px-5">
                <div className="min-w-0">
                    <CardTitle className="text-base break-words">
                        {channel.name}
                    </CardTitle>
                    <CardDescription className="mt-1 break-all">
                        {channel.target ?? 'No external target'}
                    </CardDescription>
                </div>
                <Badge
                    variant={channel.is_enabled ? 'default' : 'secondary'}
                    className="rounded-full"
                >
                    {channel.is_enabled ? 'Enabled' : 'Disabled'}
                </Badge>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 px-5">
                <div className="flex flex-wrap gap-2">
                    <Badge
                        variant={channel.is_social ? 'secondary' : 'outline'}
                        className="rounded-full"
                    >
                        {channel.type_label}
                    </Badge>
                    <Badge variant="outline" className="rounded-full">
                        {channel.has_secret ? 'Secret stored' : 'No secret'}
                    </Badge>
                    <Badge
                        variant={
                            channel.verified_at === null
                                ? 'secondary'
                                : 'outline'
                        }
                        className="rounded-full"
                    >
                        {channel.test_pending
                            ? 'Testing…'
                            : channel.verified_at === null
                              ? 'Not verified'
                              : `Verified ${channel.verified_at.slice(0, 10)}`}
                    </Badge>
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                    {isOwner ? (
                        <Form
                            action={autopublish(channel.id).url}
                            method="patch"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="rounded-full"
                                    disabled={
                                        processing ||
                                        channel.type !== 'webhook' ||
                                        (!channel.autopublish &&
                                            channel.verified_at === null)
                                    }
                                >
                                    {channel.autopublish
                                        ? 'Automatic'
                                        : 'Manual'}
                                </Button>
                            )}
                        </Form>
                    ) : (
                        <Badge variant="outline" className="rounded-full">
                            {channel.autopublish ? 'Automatic' : 'Manual'}
                        </Badge>
                    )}

                    {isOwner && channel.type === 'webhook' && (
                        <Form
                            action={ping(channel.id).url}
                            method="post"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="rounded-full"
                                    disabled={
                                        processing || channel.test_pending
                                    }
                                >
                                    <Send
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {channel.test_pending
                                        ? 'Testing…'
                                        : 'Test connection'}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * The connection wizard §7 asks for: URL, secret, then a real signed ping.
 *
 * The ping is the third step and the only one that proves anything — a saved
 * form is configuration somebody typed, and the difference shows up on
 * publication day.
 */
function ConnectDialog({
    open,
    types,
    onClose,
}: {
    open: boolean;
    types: ChannelTypeOption[];
    onClose: () => void;
}) {
    const [selectedType, setSelectedType] = useState('webhook');

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="rounded-[1.5rem]">
                <Form action={store().url} method="post" onSuccess={onClose}>
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Connect a channel</DialogTitle>
                                <DialogDescription>
                                    Add it here, then send a test delivery from
                                    the list to confirm it answers.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4 py-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="type">Type</Label>
                                    <Select
                                        name="type"
                                        value={selectedType}
                                        onValueChange={setSelectedType}
                                    >
                                        <SelectTrigger id="type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {types.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.type} />
                                </div>

                                {selectedType === 'webhook' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="endpoint">
                                            Endpoint
                                        </Label>
                                        <Input
                                            id="endpoint"
                                            name="config[endpoint]"
                                            type="url"
                                            required
                                            placeholder="https://yoursite.test/engine/webhook"
                                        />
                                        <InputError
                                            message={errors['config.endpoint']}
                                        />
                                    </div>
                                )}

                                {(selectedType === 'webhook' ||
                                    selectedType === 'pull_api') && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="secret">
                                            {selectedType === 'pull_api'
                                                ? 'Bearer token'
                                                : 'Signing secret'}
                                        </Label>
                                        <Input
                                            id="secret"
                                            name="secret"
                                            type="password"
                                            required
                                            autoComplete="off"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {selectedType === 'pull_api'
                                                ? 'The static site sends this token with every pull request.'
                                                : 'The same string the receiving site uses to verify signatures.'}{' '}
                                            Encrypted here, and never shown
                                            again.
                                        </p>
                                        <InputError message={errors.secret} />
                                    </div>
                                )}
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Add channel
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Says why the list is empty and what fills it, rather than offering an "Add
 * channel" button that would write a configuration nothing can yet deliver to.
 */
function EmptyChannels({ isOwner }: { isOwner: boolean }) {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <Radio
                    className="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <CardTitle>No channels yet</CardTitle>
                <CardDescription>
                    {isOwner
                        ? 'Connect a channel to publish to a website, webhook, or social account.'
                        : 'Ask a project owner to connect a website, webhook, or social account.'}
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

ChannelsIndex.layout = {
    breadcrumbs: [{ title: 'Channels', href: index() }],
};
