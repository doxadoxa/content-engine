import { Form, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, RotateCcw, Send } from 'lucide-react';
import Heading from '@/components/heading';
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
 * The delivery log (§7). Dead letters float to the top: everything else here is
 * history, and a dead letter is work waiting for a person.
 */
export default function Deliveries({
    deliveries,
    status,
    statuses,
    dead_letters,
    stranded,
}: Props) {
    return (
        <>
            <Head title="Delivery log" />

            <div className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Delivery log"
                        description="Every publishing attempt, newest first."
                    />
                    <div className="flex items-center gap-2">
                        {dead_letters > 0 && (
                            <Badge variant="destructive">
                                {dead_letters} failed
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
                                {stranded} stuck
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
                                        ? 'No delivery attempts on this page'
                                        : status === null
                                          ? 'No delivery attempts yet'
                                          : 'No deliveries match this status'}
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {deliveries.total > 0
                                        ? 'This page is no longer available. Return to the first page.'
                                        : status === null
                                          ? 'They will appear after content is published to a connected channel.'
                                          : 'Try another status or show all delivery attempts.'}
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
                                    <TableHead>Content</TableHead>
                                    <TableHead>Channel</TableHead>
                                    <TableHead>Event</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Tries</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-px" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {deliveries.data.map((delivery) => (
                                    <TableRow key={delivery.id}>
                                        <TableCell className="max-w-xs">
                                            {delivery.content_id === null ? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            ) : (
                                                <Link
                                                    href={`/content/${delivery.content_id}`}
                                                    className="hover:underline"
                                                >
                                                    {delivery.content ?? '—'}
                                                </Link>
                                            )}
                                            {delivery.error !== null && (
                                                <span className="block truncate text-xs text-destructive">
                                                    {delivery.error}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {delivery.channel}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {delivery.event}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {delivery.response_code ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {delivery.attempts}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    delivery.status ===
                                                    'delivered'
                                                        ? 'default'
                                                        : delivery.status ===
                                                                'dead_letter' ||
                                                            delivery.is_stranded
                                                          ? 'destructive'
                                                          : 'secondary'
                                                }
                                            >
                                                {delivery.is_stranded
                                                    ? 'Stuck'
                                                    : delivery.status ===
                                                        'dead_letter'
                                                      ? 'Failed'
                                                      : delivery.status_label}
                                            </Badge>
                                            {delivery.is_stranded && (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    Queued but never attempted.
                                                    Waiting to be retried.
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {delivery.can_replay && (
                                                <Form
                                                    action={
                                                        replay(delivery.id).url
                                                    }
                                                    method="post"
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
                                                                processing
                                                            }
                                                        >
                                                            <RotateCcw
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Resend
                                                        </Button>
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
                        Resending creates a new delivery from the original saved
                        version, so the receiving channel can distinguish it
                        from an edit.
                    </p>
                )}
            </div>
        </>
    );
}

Deliveries.layout = {
    breadcrumbs: [{ title: 'Delivery log', href: index() }],
};
