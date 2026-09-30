import { Form, Link, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    Clock,
    ExternalLink,
    Loader2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { workspacePanelClass } from '@/components/workspace-page';
import { cn } from '@/lib/utils';

export type PublicationStatus =
    | 'planned'
    | 'writing'
    | 'needs_review'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'blocked'
    | 'unscheduled'
    | 'paused'
    | 'canceled';

/** How an owner should feel about a status: see `App\Publishing\Articles\PublicationStatus`. */
export type PublicationTone =
    'neutral' | 'progress' | 'success' | 'attention' | 'problem';

export type PublicationAction = {
    label: string;
    kind:
        | 'approve'
        | 'retry'
        | 'reschedule'
        | 'connect'
        | 'review'
        | 'plan'
        | 'settings'
        | 'view';
    href: string;
    method: 'get' | 'post';
    owner_only: boolean;
    external: boolean;
};

/** One label, one sentence, one next step — the same on every screen. */
export type PublicationPresentation = {
    key: string;
    tone: PublicationTone;
    label: string;
    detail: string | null;
    when: string | null;
    action: PublicationAction | null;
    /** A second step, shown quieter: "Try again" after "Check website connection". */
    secondary: PublicationAction | null;
};

export type ArticlePublication = {
    status: PublicationStatus;
    presentation: PublicationPresentation;
    in_flight: boolean;
    publish_now: { available: boolean; reason: string | null };
    timezone: string;
    default_mode: 'automatic' | 'review_first';
    schedule: null | {
        id: string;
        version: number;
        mode: 'automatic' | 'review_first';
        held: boolean;
        status: string;
        publish_at: string;
        local_date: string;
        local_time: string;
        timezone: string;
        channel_id: string | null;
        delivery_id: string | null;
        blocked_reason: string | null;
    };
    channels: {
        id: string;
        name: string;
        type: string;
    }[];
    can_schedule: boolean;
};

const toneText: Record<PublicationTone, string> = {
    neutral: 'text-sky-700 dark:text-sky-300',
    progress: 'text-violet-700 dark:text-violet-300',
    success: 'text-emerald-700 dark:text-emerald-400',
    attention: 'text-amber-700 dark:text-amber-400',
    problem: 'text-rose-700 dark:text-rose-400',
};

const toneBorder: Record<PublicationTone, string> = {
    neutral: 'border-sky-500/40',
    progress: 'border-violet-500/40',
    success: 'border-emerald-500/40',
    attention: 'border-amber-500/50',
    problem: 'border-rose-500/50',
};

/**
 * The status's icon. Never the only cue — the label is always beside it —
 * but it lets a scanning eye find "something broke" without reading.
 */
export function PublicationIcon({
    tone,
    className,
}: {
    tone: PublicationTone;
    className?: string;
}) {
    const classes = cn('shrink-0', toneText[tone], className);

    switch (tone) {
        case 'success':
            return <CheckCircle2 className={classes} aria-hidden="true" />;
        case 'progress':
            return (
                <Loader2
                    className={cn(
                        classes,
                        'motion-safe:animate-spin motion-safe:[animation-duration:2s]',
                    )}
                    aria-hidden="true"
                />
            );
        case 'attention':
            return <Clock className={classes} aria-hidden="true" />;
        case 'problem':
            return <TriangleAlert className={classes} aria-hidden="true" />;
        default:
            return <CalendarClock className={classes} aria-hidden="true" />;
    }
}

/** A compact status: icon and label, with the sentence on hover. */
export function PublicationBadge({
    presentation,
    className,
}: {
    presentation: PublicationPresentation;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs font-medium',
                toneBorder[presentation.tone],
                toneText[presentation.tone],
                className,
            )}
            title={presentation.detail ?? undefined}
        >
            <PublicationIcon tone={presentation.tone} className="size-3" />
            {presentation.label}
        </span>
    );
}

/**
 * The status's next step, as the right kind of control: a link for a
 * destination, a form for approve and try again, an outside link for the
 * live article. Errors from the POST are said beside the button.
 */
export function PublicationActionButton({
    action,
    owner,
    size = 'sm',
    variant = 'default',
}: {
    action: PublicationAction | null;
    owner: boolean;
    size?: 'sm' | 'default';
    variant?: 'default' | 'outline';
}) {
    if (action === null || (action.owner_only && !owner)) {
        return null;
    }

    if (action.external) {
        return (
            <Button asChild size={size} variant={variant}>
                <a href={action.href} target="_blank" rel="noreferrer">
                    {action.label}
                    <ExternalLink className="size-3.5" aria-hidden="true" />
                    <span className="sr-only"> (opens in a new tab)</span>
                </a>
            </Button>
        );
    }

    if (action.method === 'post') {
        return (
            <Form
                action={action.href}
                method="post"
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-1">
                        <Button
                            type="submit"
                            size={size}
                            variant={variant}
                            disabled={processing}
                        >
                            {processing && (
                                <Loader2
                                    className="size-4 motion-safe:animate-spin"
                                    aria-hidden="true"
                                />
                            )}
                            {action.label}
                        </Button>
                        <InputError
                            role="alert"
                            className="max-w-sm text-xs"
                            message={
                                Object.values(errors).join(' ') || undefined
                            }
                        />
                    </div>
                )}
            </Form>
        );
    }

    return (
        <Button asChild size={size} variant={variant}>
            <Link href={action.href}>{action.label}</Link>
        </Button>
    );
}

/** "Publish now": approves a finished draft and sends it this minute. */
export function PublishNowButton({
    itemId,
    size = 'sm',
    variant = 'default',
}: {
    itemId: string;
    size?: 'sm' | 'default';
    variant?: 'default' | 'outline';
}) {
    return (
        <Form
            action={`/content/${itemId}/publish-now`}
            method="post"
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <div className="flex flex-col gap-1">
                    <Button
                        type="submit"
                        size={size}
                        variant={variant}
                        disabled={processing}
                    >
                        {processing && (
                            <Loader2
                                className="size-4 motion-safe:animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        Publish now
                    </Button>
                    <InputError
                        role="alert"
                        className="max-w-sm text-xs"
                        message={Object.values(errors).join(' ') || undefined}
                    />
                </div>
            )}
        </Form>
    );
}

/** Statuses where a request is on its way, or was, and the schedule is not the story. */
const UNDERWAY = ['sending', 'retrying', 'delayed', 'waiting_website'];

export function ArticlePublicationPanel({
    itemId,
    state,
    publishable,
    publication,
}: {
    itemId: string;
    /** The article's editorial state: `draft`, `approved`, … */
    state: string;
    /** Whether a draft's checks let it be approved. */
    publishable: boolean;
    publication: ArticlePublication;
}) {
    const { auth } = usePage().props;
    const owner = auth.project?.role === 'owner';
    const schedule = publication.schedule;
    const status = publication.presentation;
    const underway = publication.in_flight || UNDERWAY.includes(status.key);
    const [askedToChange, setAskedToChange] = useState(false);
    const finished = state === 'approved' || (state === 'draft' && publishable);
    const offerPublishNow =
        owner &&
        finished &&
        !underway &&
        !['published', 'failed'].includes(status.key);

    return (
        <section
            className={`${workspacePanelClass} p-5 sm:p-6`}
            aria-labelledby={`publication-${itemId}`}
        >
            <div className="flex items-start gap-3">
                <PublicationIcon tone={status.tone} className="mt-0.5 size-5" />
                <div className="min-w-0">
                    <h2 id={`publication-${itemId}`} className="font-semibold">
                        {status.label}
                    </h2>
                    {status.detail && (
                        <p className="mt-1 text-sm leading-6 text-pretty text-muted-foreground">
                            {status.detail}
                        </p>
                    )}
                    {schedule === null && status.key === 'ready' && (
                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                            {publication.default_mode === 'automatic'
                                ? 'Avyo publishes it once its checks pass, unless you hold it for your review.'
                                : 'It waits for your approval before publishing.'}
                        </p>
                    )}
                </div>
            </div>
            {/* This panel polls. A stable region says each change once. */}
            <p className="sr-only" role="status" aria-live="polite">
                {`${status.label}. ${status.detail ?? ''}`}
            </p>

            {(status.action !== null ||
                status.secondary !== null ||
                offerPublishNow) && (
                <div className="mt-4 flex flex-wrap items-start gap-2">
                    {status.action?.kind !== 'reschedule' && (
                        <PublicationActionButton
                            action={status.action}
                            owner={owner}
                        />
                    )}
                    <PublicationActionButton
                        action={status.secondary}
                        owner={owner}
                        variant="outline"
                    />
                    {offerPublishNow &&
                        (publication.publish_now.available ? (
                            <PublishNowButton
                                itemId={itemId}
                                variant={
                                    status.action !== null &&
                                    status.action.kind !== 'reschedule'
                                        ? 'outline'
                                        : 'default'
                                }
                            />
                        ) : (
                            publication.publish_now.reason && (
                                <p className="text-sm text-muted-foreground">
                                    {publication.publish_now.reason}{' '}
                                    {publication.publish_now.reason.includes(
                                        'website',
                                    ) && (
                                        <Link
                                            href="/channels"
                                            className="underline underline-offset-4"
                                        >
                                            Website connection
                                        </Link>
                                    )}
                                    {publication.publish_now.reason.includes(
                                        'plan',
                                    ) && (
                                        <Link
                                            href="/billing"
                                            className="underline underline-offset-4"
                                        >
                                            Choose a plan
                                        </Link>
                                    )}
                                </p>
                            )
                        ))}
                </div>
            )}

            {underway ? (
                owner && (
                    <div className="mt-4 border-t pt-4">
                        {askedToChange ? (
                            <p
                                className="text-sm text-muted-foreground"
                                role="status"
                            >
                                You can change the schedule once this attempt
                                finishes.
                            </p>
                        ) : (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setAskedToChange(true)}
                            >
                                Change schedule
                            </Button>
                        )}
                    </div>
                )
            ) : owner &&
              publication.can_schedule &&
              publication.channels.length > 0 ? (
                <ScheduleForm
                    key={schedule?.version ?? 'new'}
                    itemId={itemId}
                    publication={publication}
                />
            ) : (
                status.key !== 'published' && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        {!owner ? (
                            'The business owner can change when this article publishes.'
                        ) : !publication.can_schedule ? (
                            <>
                                Each attempt is listed in{' '}
                                <Link
                                    href="/deliveries"
                                    className="underline underline-offset-4"
                                >
                                    Publishing history
                                </Link>
                                .
                            </>
                        ) : (
                            <>
                                Connect your website to choose a date.{' '}
                                <Link
                                    href="/channels"
                                    className="underline underline-offset-4"
                                >
                                    Website connection
                                </Link>
                            </>
                        )}
                    </p>
                )
            )}
            {owner &&
                !underway &&
                schedule &&
                publication.can_schedule &&
                ['active', 'paused', 'blocked'].includes(schedule.status) && (
                    <div className="mt-4 flex flex-wrap gap-2 border-t pt-4">
                        <Form
                            action={`/content/${itemId}/schedule/${schedule.status === 'paused' ? 'resume' : 'pause'}`}
                            method="post"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="expected_version"
                                        value={schedule.version}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {schedule.status === 'paused'
                                            ? 'Resume schedule'
                                            : 'Pause publishing'}
                                    </Button>
                                    <InputError
                                        message={
                                            errors.schedule ??
                                            errors.expected_version
                                        }
                                    />
                                </>
                            )}
                        </Form>
                        <Form
                            action={`/content/${itemId}/schedule`}
                            method="delete"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="expected_version"
                                        value={schedule.version}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="ghost"
                                        disabled={processing}
                                    >
                                        Cancel schedule
                                    </Button>
                                    <InputError
                                        message={
                                            errors.schedule ??
                                            errors.expected_version
                                        }
                                    />
                                </>
                            )}
                        </Form>
                    </div>
                )}
        </section>
    );
}

function ScheduleForm({
    itemId,
    publication,
}: {
    itemId: string;
    publication: ArticlePublication;
}) {
    const schedule = publication.schedule;
    const [tomorrow] = useState(() =>
        new Intl.DateTimeFormat('en-CA', {
            timeZone: publication.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(Date.now() + 86400000)),
    );
    // The project decides whether articles wait for a person; the only
    // question left per article is whether to hold this one. A review-first
    // project already holds everything, so it is not asked there — and the
    // server keeps any earlier hold when `hold` is not sent.
    const automatic = publication.default_mode === 'automatic';
    const chooseWebsite = publication.channels.length > 1;
    const form = useForm({
        expected_version: schedule?.version ?? null,
        local_date: schedule?.local_date ?? tomorrow,
        local_time: schedule?.local_time.slice(0, 5) ?? '09:00',
        hold: schedule?.held ?? false,
        channel_id: schedule?.channel_id ?? publication.channels[0]?.id ?? '',
    });
    form.transform(({ hold, channel_id, ...data }) => ({
        ...data,
        ...(automatic ? { hold } : {}),
        ...(chooseWebsite ? { channel_id } : {}),
    }));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/content/${itemId}/schedule`, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
        >
            <div className="space-y-2">
                <Label htmlFor={`date-${itemId}`}>Publication date</Label>
                <Input
                    id={`date-${itemId}`}
                    type="date"
                    required
                    value={form.data.local_date}
                    onChange={(event) =>
                        form.setData('local_date', event.target.value)
                    }
                />
                <InputError message={form.errors.local_date} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`time-${itemId}`}>
                    Time · {publication.timezone}
                </Label>
                <Input
                    id={`time-${itemId}`}
                    type="time"
                    required
                    value={form.data.local_time}
                    onChange={(event) =>
                        form.setData('local_time', event.target.value)
                    }
                />
                <InputError message={form.errors.local_time} />
            </div>
            {chooseWebsite && (
                <div className="space-y-2">
                    <Label htmlFor={`channel-${itemId}`}>Website</Label>
                    <select
                        id={`channel-${itemId}`}
                        required
                        className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                        value={form.data.channel_id}
                        onChange={(event) =>
                            form.setData('channel_id', event.target.value)
                        }
                    >
                        {publication.channels.map((channel) => (
                            <option key={channel.id} value={channel.id}>
                                {channel.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.channel_id} />
                </div>
            )}
            {automatic && (
                <div className="flex items-start gap-2 sm:col-span-2 lg:col-span-4">
                    <Checkbox
                        id={`hold-${itemId}`}
                        checked={form.data.hold}
                        onCheckedChange={(checked) =>
                            form.setData('hold', checked === true)
                        }
                    />
                    <Label htmlFor={`hold-${itemId}`}>
                        Hold this article for my review
                    </Label>
                    <InputError message={form.errors.hold} />
                </div>
            )}
            <div className="sm:col-span-2 lg:col-span-4">
                <Button type="submit" disabled={form.processing}>
                    {form.processing
                        ? 'Saving…'
                        : schedule
                          ? 'Save publishing settings'
                          : 'Schedule article'}
                </Button>
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    {automatic
                        ? 'Articles publish only after their quality checks pass. A held article waits for your approval, even once its date has arrived.'
                        : 'This project reviews every article first, so this one waits for your approval, even once its date has arrived. You can change that in project settings.'}
                </p>
                <InputError message={form.errors.expected_version} />
                <InputError
                    message={Object.entries(form.errors)
                        .filter(
                            ([key]) =>
                                ![
                                    'expected_version',
                                    'local_date',
                                    'local_time',
                                    'hold',
                                    'channel_id',
                                ].includes(key),
                        )
                        .map(([, message]) => message)
                        .join(' ')}
                />
            </div>
        </form>
    );
}
