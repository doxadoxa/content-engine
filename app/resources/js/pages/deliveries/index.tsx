import { Form, Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, RotateCcw } from 'lucide-react';
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
            <Head title="Deliveries" />

            <div className="flex min-w-0 flex-col gap-6 p-4 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Deliveries"
                        description="Every attempt to hand a unit to a channel, newest first."
                    />
                    <div className="flex items-center gap-2">
                        {dead_letters > 0 && (
                            <Badge variant="destructive">
                                {dead_letters} dead letter
                                {dead_letters === 1 ? '' : 's'}
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
                                {stranded} stranded
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
                                                delivery.status === 'delivered'
                                                    ? 'default'
                                                    : delivery.status ===
                                                            'dead_letter' ||
                                                        delivery.is_stranded
                                                      ? 'destructive'
                                                      : 'secondary'
                                            }
                                        >
                                            {delivery.is_stranded
                                                ? 'Stranded'
                                                : delivery.status_label}
                                        </Badge>
                                        {delivery.is_stranded && (
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                queued and never attempted —
                                                waiting to be swept back into
                                                the queue
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {delivery.can_replay && (
                                            <Form
                                                action={replay(delivery.id).url}
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
                                                        disabled={processing}
                                                    >
                                                        <RotateCcw
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        Replay
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

                <Pagination page={deliveries} />

                <p className="text-xs text-muted-foreground">
                    A replay re-sends the stored snapshot under a new delivery
                    id — the bytes are the ones that were sent originally, so a
                    receiver can still tell a repeat from an edit.
                </p>
            </div>
        </>
    );
}

Deliveries.layout = {
    breadcrumbs: [{ title: 'Deliveries', href: index() }],
};
