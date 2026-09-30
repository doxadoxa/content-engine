import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, RotateCcw, Send } from 'lucide-react';
import { PublicationBadge } from '@/components/article-publication';
import type { PublicationTone } from '@/components/article-publication';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
import { index, replay } from '@/routes/deliveries';
import type { Paginated } from '@/types';

type Delivery = {
    id: string;
    label: string;
    tone: PublicationTone;
    explanation: string | null;
    next_attempt: string | null;
    is_test: boolean;
    delivery_id: string;
    event: string;
    status: string;
    status_label: string;
    response_code: number | null;
    latency_ms: number | null;
    attempts: number;
    error: string | null;
    next_attempt_at: string | null;
    created_at: string | null;
    channel: string;
    content: string | null;
    content_id: string | null;
    can_replay: boolean;
    is_stranded: boolean;
};

type Props = {
    deliveries: Paginated<Delivery>;
    status: string | null;
    statuses: { value: string; label: string }[];
    dead_letters: number;
    stranded: number;
};

/**
 * Publishing history (§7): every time Avyo sent something to the website.
 * Failures float to the top: everything else here is history, and a failure is
 * work waiting for a person.
 */
export default function Deliveries({
    deliveries,
    status,
    statuses,
    dead_letters,
    stranded,
}: Props) {
    const { auth } = usePage().props;
    const owner = auth.project?.role === 'owner';

    return (
        <>
            <Head title="Publishing history" />

            <div className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Publishing history"
                        description="Each time Avyo sent an article or a test to your website, newest first."
                    />
                    <div className="flex items-center gap-2">
                        {dead_letters > 0 && (
                            <Badge variant="destructive">
                                {dead_letters} couldn’t publish
                            </Badge>
                        )}
                        {/* A pending row looks healthy, which is why the one
                            failure with no automatic way out was also the one
                            nothing on this screen mentioned. */}
                        {stranded > 0 && (
                            <Badge variant="destructive">
                                <AlertTriangle
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                {stranded} delayed
                            </Badge>
                        )}
                        <Select
                            value={status ?? 'all'}
                            onValueChange={(value) =>
                                router.get(
                                    index({
                                        query:
                                            value === 'all'
                                                ? {}
                                                : { status: value },
                                    }),
                                )
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All statuses
                                </SelectItem>
                                {statuses.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {deliveries.data.length === 0 ? (
                    <Card className="py-12">
                        <div className="flex flex-col items-center gap-3 px-6 text-center">
                            <Send
                                className="size-8 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div>
                                <h2 className="font-semibold tracking-tight">
                                    {deliveries.total > 0
                                        ? 'Nothing on this page'
                                        : status === null
                                          ? 'Nothing sent yet'
                                          : 'Nothing matches this status'}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {deliveries.total > 0
                                        ? 'This page is no longer available. Return to the first page.'
                                        : status === null
                                          ? 'Articles appear here once Avyo sends them to your website.'
                                          : 'Choose another status, or show everything.'}
                                </p>
                            </div>
                            {(status !== null || deliveries.total > 0) && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.get(
                                            index({
                                                query:
                                                    deliveries.total > 0 &&
                                                    status !== null
                                                        ? { status }
                                                        : {},
                                            }),
                                        )
                                    }
                                >
                                    {deliveries.total > 0
                                        ? 'Go to first page'
                                        : 'Show all statuses'}
                                </Button>
                            )}
                        </div>
                    </Card>
                ) : (
                    <Card className="max-w-full overflow-x-auto p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Article</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Website</TableHead>
                                    <TableHead>Sent</TableHead>
                                    <TableHead className="w-px">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {deliveries.data.map((delivery) => (
                                    <TableRow
                                        key={delivery.id}
                                        className="align-top"
                                    >
                                        <TableCell className="max-w-xs whitespace-normal">
                                            {delivery.content_id === null ? (
                                                <span className="text-muted-foreground">
                                                    {delivery.is_test
                                                        ? 'Connection test'
                                                        : '—'}
                                                </span>
                                            ) : (
                                                <Link
                                                    href={`/content/${delivery.content_id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {delivery.content ?? '—'}
                                                </Link>
                                            )}
                                            <details className="mt-1 text-xs text-muted-foreground">
                                                <summary className="cursor-pointer rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                                    Technical details
                                                </summary>
                                                <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                                    <dt>Event</dt>
                                                    <dd>
                                                        {delivery.event || '—'}
                                                    </dd>
                                                    <dt>Response</dt>
                                                    <dd>
                                                        {delivery.response_code ??
                                                            'None'}
                                                    </dd>
                                                    <dt>Tries</dt>
                                                    <dd>{delivery.attempts}</dd>
                                                    <dt>Reference</dt>
                                                    <dd className="font-mono break-all">
                                                        {delivery.delivery_id}
                                                    </dd>
                                                    {delivery.error && (
                                                        <>
                                                            <dt>Message</dt>
                                                            <dd className="break-words">
                                                                {delivery.error}
                                                            </dd>
                                                        </>
                                                    )}
                                                </dl>
                                            </details>
                                        </TableCell>
                                        <TableCell className="max-w-sm whitespace-normal">
                                            <PublicationBadge
                                                presentation={{
                                                    key: delivery.status,
                                                    tone: delivery.tone,
                                                    label: delivery.label,
                                                    detail: null,
                                                    when: null,
                                                    action: null,
                                                }}
                                            />
                                            {delivery.explanation && (
                                                <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                                                    {delivery.explanation}
                                                </span>
                                            )}
                                            {delivery.next_attempt && (
                                                <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                                                    {delivery.next_attempt}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {delivery.channel}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {delivery.created_at &&
                                                new Date(
                                                    delivery.created_at,
                                                ).toLocaleString(undefined, {
                                                    dateStyle: 'medium',
                                                    timeStyle: 'short',
                                                })}
                                        </TableCell>
                                        <TableCell>
                                            {owner && delivery.can_replay && (
                                                <Form
                                                    action={
                                                        replay(delivery.id).url
                                                    }
                                                    method="post"
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({
                                                        processing,
                                                        errors,
                                                    }) => (
                                                        <div className="flex flex-col items-end gap-1">
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <RotateCcw
                                                                    className="size-4"
                                                                    aria-hidden="true"
                                                                />
                                                                Try again
                                                            </Button>
                                                            <InputError
                                                                role="alert"
                                                                className="max-w-56 text-right text-xs"
                                                                message={
                                                                    errors.delivery
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </Form>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}

                <Pagination page={deliveries} />

                {deliveries.data.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Trying again sends the version of the article Avyo saved
                        the first time, so your website can tell it apart from
                        an edit.
                    </p>
                )}
            </div>
        </>
    );
}

Deliveries.layout = {
    breadcrumbs: [{ title: 'Publishing history', href: index() }],
};
