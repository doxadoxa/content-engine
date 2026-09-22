import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
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
import { index, ping, store } from '@/routes/channels';

type ChannelRow = {
    id: string;
    name: string;
    type: string;
    type_label: string;
    is_social: boolean;
    is_enabled: boolean;
    /** Whether a secret is stored — never the secret itself. */
    has_secret: boolean;
    native_config: {
        page_receiver_base: string;
        username: string;
        endpoint: string;
    };
    autopublish: boolean;
    can_schedule_articles: boolean;
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

export default function ChannelsIndex({ channels: allChannels, types }: Props) {
    const channels = allChannels.filter((channel) => !channel.is_social);
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
            <Head title="Website connection" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Publishing"
                    context={`${channels.length} ${channels.length === 1 ? 'channel' : 'channels'}`}
                    title="Your website connection"
                    description="Connect the website where your articles will appear. After a successful test, enable scheduled publishing."
                    actions={
                        isOwner ? (
                            <Button
                                className="rounded-full"
                                onClick={() => setConnecting(true)}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Connect your website
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
                                                        [
                                                            'webhook',
                                                            'wordpress',
                                                        ].includes(
                                                            channel.type,
                                                        ) &&
                                                        Boolean(
                                                            channel
                                                                .native_config
                                                                .endpoint ||
                                                            channel
                                                                .native_config
                                                                .page_receiver_base,
                                                        ) && (
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

                {isOwner &&
                    channels
                        .filter((channel) =>
                            ['wordpress', 'webhook'].includes(channel.type),
                        )
                        .map((channel) => (
                            <details
                                key={channel.id}
                                className={`${workspacePanelClass} p-5`}
                            >
                                <summary className="cursor-pointer font-medium">
                                    Connection settings · {channel.name}
                                </summary>
                                <Form
                                    action={`/channels/${channel.id}`}
                                    method="patch"
                                    options={{ preserveScroll: true }}
                                    className="mt-4 grid gap-3"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="name"
                                                value={channel.name}
                                            />
                                            <input
                                                type="hidden"
                                                name="type"
                                                value={channel.type}
                                            />
                                            {channel.type === 'webhook' && (
                                                <>
                                                    <Label
                                                        htmlFor={`endpoint-${channel.id}`}
                                                    >
                                                        Article publishing
                                                        address
                                                    </Label>
                                                    <Input
                                                        id={`endpoint-${channel.id}`}
                                                        name="config[endpoint]"
                                                        type="url"
                                                        defaultValue={
                                                            channel
                                                                .native_config
                                                                .endpoint
                                                        }
                                                    />
                                                </>
                                            )}
                                            <Label
                                                htmlFor={`receiver-${channel.id}`}
                                            >
                                                Avyo publishing address
                                            </Label>
                                            <Input
                                                id={`receiver-${channel.id}`}
                                                name="config[page_receiver_base]"
                                                type="url"
                                                defaultValue={
                                                    channel.native_config
                                                        .page_receiver_base
                                                }
                                                required={
                                                    channel.type === 'wordpress'
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        'config.page_receiver_base'
                                                    ]
                                                }
                                            />
                                            {channel.type === 'wordpress' && (
                                                <>
                                                    <Label
                                                        htmlFor={`username-${channel.id}`}
                                                    >
                                                        WordPress account name
                                                    </Label>
                                                    <Input
                                                        id={`username-${channel.id}`}
                                                        name="config[username]"
                                                        defaultValue={
                                                            channel
                                                                .native_config
                                                                .username
                                                        }
                                                        required
                                                    />
                                                    <InputError
                                                        message={
                                                            errors[
                                                                'config.username'
                                                            ]
                                                        }
                                                    />
                                                </>
                                            )}
                                            <Label
                                                htmlFor={`password-${channel.id}`}
                                            >
                                                {channel.type === 'wordpress'
                                                    ? 'Replacement application password'
                                                    : 'Replacement receiver secret'}
                                            </Label>
                                            <Input
                                                id={`password-${channel.id}`}
                                                name="secret"
                                                type="password"
                                                autoComplete="new-password"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Leave blank to keep the stored
                                                credential. Changes require a
                                                new source read and review
                                                before native publication.
                                            </p>
                                            <input
                                                type="hidden"
                                                name="is_enabled"
                                                value="0"
                                            />
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    name="is_enabled"
                                                    value="1"
                                                    defaultChecked={
                                                        channel.is_enabled
                                                    }
                                                />{' '}
                                                Connection enabled
                                            </label>
                                            <input
                                                type="hidden"
                                                name="autopublish"
                                                value="0"
                                            />
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    name="autopublish"
                                                    value="1"
                                                    defaultChecked={
                                                        channel.autopublish
                                                    }
                                                    disabled={
                                                        !channel.can_schedule_articles &&
                                                        !channel.autopublish
                                                    }
                                                />
                                                Publish scheduled articles
                                                automatically
                                            </label>
                                            <p className="text-xs leading-5 text-muted-foreground">
                                                {channel.can_schedule_articles
                                                    ? 'Allows Avyo to use this website for newly scheduled articles. Existing unscheduled approvals stay unchanged; review-first articles still wait for approval.'
                                                    : 'Send a successful article publishing test before enabling this option.'}
                                            </p>
                                            <InputError
                                                message={errors.autopublish}
                                            />
                                            <InputError
                                                message={errors.secret}
                                            />
                                            <Button
                                                disabled={processing}
                                                type="submit"
                                                className="justify-self-start"
                                            >
                                                Save connection
                                            </Button>
                                            <Link
                                                href="/pages"
                                                className="text-sm underline"
                                            >
                                                Advanced: update existing pages
                                            </Link>
                                        </>
                                    )}
                                </Form>
                            </details>
                        ))}
                <div className="rounded-[1.25rem] border border-dashed bg-card/45 px-5 py-4 text-xs leading-relaxed text-muted-foreground">
                    <p>
                        Credentials are stored securely. A publishing test
                        checks that this website can receive articles. Choose
                        automatic publishing or review first on each article’s
                        schedule. Existing-page changes remain a separate
                        reviewed workflow.
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
                    types={types.filter((type) => !type.is_social)}
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
    const unverified = channels.filter(
        (channel) => channel.verified_at === null,
    ).length;
    const enabled = channels.filter((channel) => channel.is_enabled).length;

    return (
        <section
            className={`${workspacePanelClass} grid grid-cols-3 divide-x px-2 py-4 text-center sm:px-4`}
            aria-label="Channel overview"
        >
            <ChannelMetric label="Enabled" value={enabled} />
            <ChannelMetric label="Verified" value={verified} />
            <ChannelMetric label="Needs testing" value={unverified} />
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
                        {channel.has_secret
                            ? 'Credentials saved'
                            : 'Credentials needed'}
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

                <p className="text-xs text-muted-foreground">
                    {channel.autopublish && channel.can_schedule_articles
                        ? 'Automatic publishing enabled'
                        : 'Automatic publishing not enabled'}
                </p>
                <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                    {isOwner &&
                        ['webhook', 'wordpress'].includes(channel.type) &&
                        Boolean(
                            channel.native_config.endpoint ||
                            channel.native_config.page_receiver_base,
                        ) && (
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
    const [selectedType, setSelectedType] = useState('wordpress');
    const [receiverAddress, setReceiverAddress] = useState('');

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="rounded-[1.5rem]">
                <Form action={store().url} method="post" onSuccess={onClose}>
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Connect your website</DialogTitle>
                                <DialogDescription>
                                    Choose your website and add its publishing
                                    credentials. Then send a test from the
                                    connection list to confirm that articles can
                                    be received.
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
                                            Article publishing address
                                        </Label>
                                        <Input
                                            id="endpoint"
                                            name="config[endpoint]"
                                            type="url"
                                            placeholder="https://yoursite.test/engine/webhook"
                                        />
                                        <InputError
                                            message={errors['config.endpoint']}
                                        />
                                    </div>
                                )}

                                {selectedType === 'wordpress' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="wordpress-site">
                                            WordPress website address
                                        </Label>
                                        <Input
                                            id="wordpress-site"
                                            type="url"
                                            required
                                            placeholder="https://yourbusiness.com"
                                            onChange={(event) => {
                                                try {
                                                    const url = new URL(
                                                        event.target.value,
                                                    );
                                                    setReceiverAddress(
                                                        `${url.origin}${url.pathname.replace(/\/$/, '')}/wp-json/avyo/v1`,
                                                    );
                                                } catch {
                                                    setReceiverAddress('');
                                                }
                                            }}
                                        />
                                        <details className="text-xs">
                                            <summary className="cursor-pointer text-muted-foreground">
                                                Advanced publishing address
                                            </summary>
                                            <Input
                                                aria-label="Avyo publishing address"
                                                name="config[page_receiver_base]"
                                                type="url"
                                                required
                                                value={receiverAddress}
                                                onChange={(event) =>
                                                    setReceiverAddress(
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-2"
                                            />
                                        </details>
                                        <InputError
                                            message={
                                                errors[
                                                    'config.page_receiver_base'
                                                ]
                                            }
                                        />
                                    </div>
                                )}
                                {selectedType === 'webhook' && (
                                    <details className="text-xs">
                                        <summary className="cursor-pointer text-muted-foreground">
                                            Advanced: existing-page updates
                                        </summary>
                                        <Input
                                            aria-label="Existing-page receiver address"
                                            name="config[page_receiver_base]"
                                            type="url"
                                            placeholder="https://yourbusiness.com/api/avyo/pages/v1"
                                            className="mt-2"
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    'config.page_receiver_base'
                                                ]
                                            }
                                        />
                                    </details>
                                )}
                                {selectedType === 'wordpress' && (
                                    <div className="grid gap-2">
                                        <p className="text-xs text-muted-foreground">
                                            <a
                                                href="/integrations/wordpress/receiver.zip"
                                                className="font-medium underline"
                                            >
                                                Download the Avyo WordPress
                                                plugin
                                            </a>
                                            . In WordPress, open Plugins → Add
                                            Plugin → Upload Plugin, install this
                                            ZIP and activate it. Then create an
                                            application password in the
                                            dedicated editor's user profile.
                                        </p>
                                        <Label htmlFor="wordpress-username">
                                            WordPress account name
                                        </Label>
                                        <Input
                                            id="wordpress-username"
                                            name="config[username]"
                                            required
                                        />
                                        <InputError
                                            message={errors['config.username']}
                                        />
                                    </div>
                                )}
                                {(selectedType === 'webhook' ||
                                    selectedType === 'wordpress' ||
                                    selectedType === 'pull_api') && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="secret">
                                            {selectedType === 'wordpress'
                                                ? 'WordPress application password'
                                                : selectedType === 'pull_api'
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
                                            {selectedType === 'wordpress'
                                                ? 'Use a revocable application password from the WordPress user profile.'
                                                : selectedType === 'pull_api'
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
                        ? 'Connect your website to publish approved changes.'
                        : 'Ask a project owner to connect the website.'}
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

ChannelsIndex.layout = {
    breadcrumbs: [{ title: 'Channels', href: index() }],
};
