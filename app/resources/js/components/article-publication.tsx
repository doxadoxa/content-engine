import { Form, Link, useForm, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { workspacePanelClass } from '@/components/workspace-page';

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
export type ArticlePublication = {
    status: PublicationStatus;
    timezone: string;
    default_mode: 'automatic' | 'review_first';
    schedule: null | {
        id: string;
        version: number;
        mode: 'automatic' | 'review_first';
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
        autopublish: boolean;
    }[];
    can_schedule: boolean;
};
export const publicationLabels: Record<PublicationStatus, string> = {
    planned: 'Planned',
    writing: 'Being written',
    needs_review: 'Needs review',
    scheduled: 'Scheduled',
    publishing: 'Publishing',
    published: 'Published',
    blocked: 'Needs attention',
    unscheduled: 'Choose a date',
    paused: 'Paused',
    canceled: 'Schedule canceled',
};

export function ArticlePublicationPanel({
    itemId,
    approved,
    publication,
}: {
    itemId: string;
    approved: boolean;
    publication: ArticlePublication;
}) {
    const { auth } = usePage().props;
    const owner = auth.project?.role === 'owner';
    const schedule = publication.schedule;
    const nextStep =
        publication.status === 'published'
            ? 'Publication completed'
            : publication.status === 'paused'
              ? 'Publication is paused'
              : publication.status === 'canceled'
                ? 'This schedule was canceled'
                : publication.status === 'blocked'
                  ? 'Publication needs attention'
                  : publication.status === 'publishing'
                    ? 'Delivery is in progress'
                    : approved
                      ? 'Approved · ready at the scheduled time'
                      : schedule?.mode === 'automatic' &&
                          publication.status !== 'needs_review'
                        ? 'Publish automatically after checks'
                        : 'Your approval is required first';

    return (
        <section
            className={`${workspacePanelClass} p-5 sm:p-6`}
            aria-label="Publication schedule"
        >
            <div className="flex items-start gap-3">
                <CalendarDays className="mt-0.5 size-5 shrink-0 text-sage" />
                <div>
                    <h2 className="font-semibold">
                        {publicationLabels[publication.status]}
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {schedule
                            ? `Scheduled for ${schedule.local_date} at ${schedule.local_time} (${schedule.timezone}) · ${nextStep}`
                            : 'Choose when this article should reach your customers. You can let Avyo publish automatically or review it first.'}
                    </p>
                </div>
            </div>
            {schedule?.blocked_reason && (
                <p className="mt-4 rounded-xl border border-amber-500/30 bg-amber-50/30 p-3 text-sm dark:bg-amber-950/20">
                    {schedule.blocked_reason}{' '}
                    <Link
                        href="/channels"
                        className="underline underline-offset-4"
                    >
                        Website publishing settings
                    </Link>
                </p>
            )}
            {owner &&
                publication.status === 'blocked' &&
                !publication.can_schedule &&
                schedule?.delivery_id && (
                    <Form
                        action={`/deliveries/${schedule.delivery_id}/replay`}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="mt-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Retrying publication…'
                                        : 'Retry publication'}
                                </Button>
                                <InputError message={errors.delivery} />
                            </>
                        )}
                    </Form>
                )}
            {owner &&
            publication.can_schedule &&
            publication.channels.length > 0 ? (
                <ScheduleForm
                    key={schedule?.version ?? 'new'}
                    itemId={itemId}
                    publication={publication}
                />
            ) : (
                publication.status !== 'published' &&
                publication.status !== 'publishing' && (
                    <p className="mt-4 text-sm text-muted-foreground">
                        {!owner ? (
                            'The business owner can change publishing settings.'
                        ) : !publication.can_schedule ? (
                            <>
                                Publication has already been attempted. Check
                                its outcome before taking another action.{' '}
                                <Link
                                    href="/deliveries"
                                    className="underline underline-offset-4"
                                >
                                    Publishing history
                                </Link>
                            </>
                        ) : (
                            <>
                                Connect a website that can receive articles to
                                enable scheduling.{' '}
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
                schedule &&
                publication.can_schedule &&
                ['active', 'paused', 'blocked', 'dispatching'].includes(
                    schedule.status,
                ) && (
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
                                            : 'Pause publication'}
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
    const form = useForm({
        expected_version: schedule?.version ?? null,
        local_date: schedule?.local_date ?? tomorrow,
        local_time: schedule?.local_time.slice(0, 5) ?? '09:00',
        mode: schedule?.mode ?? publication.default_mode,
        channel_id: schedule?.channel_id ?? publication.channels[0]?.id ?? '',
    });
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
            <div className="space-y-2">
                <Label htmlFor={`mode-${itemId}`}>Before publication</Label>
                <select
                    id={`mode-${itemId}`}
                    className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                    value={form.data.mode}
                    onChange={(event) =>
                        form.setData(
                            'mode',
                            event.target.value as 'automatic' | 'review_first',
                        )
                    }
                >
                    <option value="automatic">Publish automatically</option>
                    <option value="review_first">Let me review first</option>
                </select>
                <InputError message={form.errors.mode} />
            </div>
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
            <div className="sm:col-span-2 lg:col-span-4">
                <Button type="submit" disabled={form.processing}>
                    {form.processing
                        ? 'Saving…'
                        : schedule
                          ? 'Save publishing settings'
                          : 'Schedule article'}
                </Button>
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    Automatic articles publish only after quality checks pass.
                    Review-first articles wait for your approval, even if their
                    date has arrived.
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
                                    'mode',
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
