import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import type { Paginated } from '@/types';

type Source = {
    id: string;
    name: string;
    kind: 'webhook' | 'manual';
    is_primary: boolean;
    is_enabled: boolean;
    tracking_started_at: string | null;
    first_received_at: string | null;
    last_received_at: string | null;
    verified_at: string | null;
    verification_note: string | null;
    endpoint: string | null;
};
type Sale = {
    id: string;
    source_id: string;
    transaction_id: string;
    revision: number;
    status: string;
    amount_minor: number;
    refunded_minor: number;
    currency: string;
    purchased_at: string | null;
    occurred_at: string;
    attribution_status: string;
    landing_url: string | null;
    is_new_customer: boolean | null;
    evidence: string;
    items: { item_id: string; item_name: string; quantity: number }[];
};
type Currency = {
    currency: string;
    completed_sales: number;
    fully_refunded_sales: number;
    cancelled_sales: number;
    received_minor: number;
    refunded_minor: number;
    net_minor: number;
    attributed_sales: number;
    unattributed_sales: number;
    new_customer_sales: number;
    returning_customer_sales: number;
    unknown_customer_sales: number;
};
type Props = {
    sources: Source[];
    records: Paginated<Sale>;
    canManage: boolean;
    summary: {
        from: string;
        to: string;
        status: string;
        source_name: string | null;
        currencies: Currency[] | null;
        recorded_balances:
            | {
                  currency: string;
                  records: number;
                  received_minor: number;
                  refunded_minor: number;
                  net_minor: number;
                  partially_paid: number;
                  cancelled: number;
                  reconciliation_required: number;
              }[]
            | null;
        limitations: string[];
    };
};
const selectClass =
    'h-10 w-full rounded-md border border-input bg-background px-3 text-sm';
function money(minor: number, currency: string) {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });

    return formatter.format(
        minor / 10 ** (formatter.resolvedOptions().maximumFractionDigits ?? 2),
    );
}
function date(value: string | null) {
    return value === null
        ? 'Not yet recorded'
        : new Date(value).toLocaleString();
}

export default function Purchases({
    sources,
    records,
    canManage,
    summary,
}: Props) {
    const credential = usePage().flash.purchase_credential as
        { source_id: string; secret: string } | undefined;
    const [editing, setEditing] = useState<Sale | null>(null);
    const sourceForm = useForm({ name: '', kind: 'webhook' });

    return (
        <>
            <Head title="Purchases" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Performance"
                    title="Purchases"
                    description="Actual received payments, reconciled with your sales records. Bookings and partial payments stay separate from completed purchases."
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/performance">Search performance</Link>
                        </Button>
                    }
                />
                {credential && canManage && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle>Save your connection key</CardTitle>
                            <CardDescription>
                                This key appears once. Share it only with the
                                person connecting your payment source. Rotating
                                it invalidates the previous key.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Label htmlFor="purchase-key">
                                Connection key for{' '}
                                {
                                    sources.find(
                                        (s) => s.id === credential.source_id,
                                    )?.name
                                }
                            </Label>
                            <Input
                                id="purchase-key"
                                readOnly
                                value={credential.secret}
                                className="mt-2 font-mono"
                                autoComplete="off"
                            />
                        </CardContent>
                    </Card>
                )}
                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle>
                            Completed-sale cohort · last 28 days ·{' '}
                            {summary.source_name ?? 'Choose a purchase source'}
                        </CardTitle>
                        <CardDescription>
                            {summary.status === 'unavailable'
                                ? 'Purchase totals are unavailable until records arrive.'
                                : summary.status === 'unverified'
                                  ? 'Received records — verification required.'
                                  : 'Reconciled purchase records. Collection completeness still needs checking.'}{' '}
                            Only the primary source contributes to this summary.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {summary.currencies?.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No completed purchases received for this window.
                                This does not prove that there were no sales.
                            </p>
                        )}
                        {summary.currencies?.map((c) => (
                            <div
                                key={c.currency}
                                className="grid gap-4 rounded-xl border p-4 sm:grid-cols-3"
                            >
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Completed sales · {c.currency}
                                    </p>
                                    <p className="text-2xl font-semibold">
                                        {c.completed_sales}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {c.fully_refunded_sales} fully refunded;{' '}
                                        {c.cancelled_sales} cancelled
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        This cohort: received / refunded / net
                                    </p>
                                    <p className="font-semibold">
                                        {money(c.received_minor, c.currency)} /{' '}
                                        {money(c.refunded_minor, c.currency)} /{' '}
                                        {money(c.net_minor, c.currency)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm">
                                        {c.attributed_sales} matched to a page;{' '}
                                        {c.unattributed_sales} unattributed
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        New customers: {c.new_customer_sales} ·
                                        Returning: {c.returning_customer_sales}{' '}
                                        · Unknown: {c.unknown_customer_sales}
                                    </p>
                                </div>
                            </div>
                        ))}
                        {summary.recorded_balances &&
                            summary.recorded_balances.length > 0 && (
                                <section className="space-y-3 border-t pt-4">
                                    <h3 className="font-semibold">
                                        Current balances of all received sale
                                        records
                                    </h3>
                                    <p className="text-xs text-muted-foreground">
                                        Includes partial payments and retained
                                        deposits after cancellation. These
                                        balances cover all recorded
                                        transactions; they are not cash flow
                                        during the 28-day window.
                                    </p>
                                    {summary.recorded_balances.map(
                                        (balance) => (
                                            <div
                                                key={balance.currency}
                                                className="rounded-xl border p-3"
                                            >
                                                <p className="text-sm">
                                                    {balance.currency} ·
                                                    Received{' '}
                                                    {money(
                                                        balance.received_minor,
                                                        balance.currency,
                                                    )}{' '}
                                                    · Refunded{' '}
                                                    {money(
                                                        balance.refunded_minor,
                                                        balance.currency,
                                                    )}{' '}
                                                    · Net{' '}
                                                    {money(
                                                        balance.net_minor,
                                                        balance.currency,
                                                    )}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {balance.records} records ·{' '}
                                                    {balance.partially_paid}{' '}
                                                    partially paid ·{' '}
                                                    {balance.cancelled}{' '}
                                                    cancelled
                                                </p>
                                                {balance.reconciliation_required >
                                                    0 && (
                                                    <p className="mt-2 text-sm font-semibold text-amber-700 dark:text-amber-300">
                                                        {
                                                            balance.reconciliation_required
                                                        }{' '}
                                                        inconsistent ledger{' '}
                                                        {balance.reconciliation_required ===
                                                        1
                                                            ? 'record needs'
                                                            : 'records need'}{' '}
                                                        reconciliation. Actual
                                                        receipts and refunds
                                                        have been retained.
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    )}
                                </section>
                            )}
                        <ul className="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                            {summary.limitations.map((l) => (
                                <li key={l}>{l}</li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
                <div className="grid items-start gap-5 xl:grid-cols-2">
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold">
                            Purchase sources
                        </h2>
                        {sources.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Connect the place where a sale becomes paid, or
                                record an explicitly manual reconciliation.
                            </p>
                        )}
                        {sources.map((source) => (
                            <SourceCard
                                key={source.id}
                                source={source}
                                canManage={canManage}
                            />
                        ))}
                        {canManage && (
                            <Card className={workspacePanelClass}>
                                <CardHeader>
                                    <CardTitle>Add a source</CardTitle>
                                    <CardDescription>
                                        Choose one primary source. Adding the
                                        same sale from different systems will
                                        not combine their totals.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        className="space-y-3"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            sourceForm.post(
                                                '/purchases/sources',
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        sourceForm.reset(),
                                                },
                                            );
                                        }}
                                    >
                                        <Label htmlFor="source-name">
                                            Source name
                                        </Label>
                                        <Input
                                            id="source-name"
                                            value={sourceForm.data.name}
                                            onChange={(e) =>
                                                sourceForm.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            maxLength={100}
                                        />
                                        <InputError
                                            message={sourceForm.errors.name}
                                        />
                                        <Label htmlFor="source-kind">
                                            Collection method
                                        </Label>
                                        <select
                                            id="source-kind"
                                            className={selectClass}
                                            value={sourceForm.data.kind}
                                            onChange={(e) =>
                                                sourceForm.setData(
                                                    'kind',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="webhook">
                                                Connected payment records
                                            </option>
                                            <option value="manual">
                                                Manual reconciliation
                                            </option>
                                        </select>
                                        <InputError
                                            message={sourceForm.errors.kind}
                                        />
                                        <Button
                                            disabled={sourceForm.processing}
                                        >
                                            Create source
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                    {canManage && (
                        <ManualRecord
                            key={editing?.id ?? 'new'}
                            sources={sources.filter(
                                (s) => s.kind === 'manual' && s.is_enabled,
                            )}
                            sale={editing}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </div>
                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle>Received sale states</CardTitle>
                        <CardDescription>
                            All sources are shown separately. A correction uses
                            the same transaction ID and a higher revision.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {records.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No sales records received yet.
                            </p>
                        )}
                        {records.data.map((sale) => (
                            <div
                                key={sale.id}
                                className="flex flex-wrap items-start justify-between gap-3 rounded-xl border p-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="font-medium break-words">
                                        {sale.transaction_id}{' '}
                                        <Badge variant="outline">
                                            {sale.status.replaceAll('_', ' ')}
                                        </Badge>
                                    </p>
                                    <p className="text-sm">
                                        {
                                            sources.find(
                                                (s) => s.id === sale.source_id,
                                            )?.name
                                        }{' '}
                                        · Revision {sale.revision} ·{' '}
                                        {money(
                                            sale.amount_minor,
                                            sale.currency,
                                        )}{' '}
                                        received ·{' '}
                                        {money(
                                            sale.refunded_minor,
                                            sale.currency,
                                        )}{' '}
                                        refunded
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Purchase: {date(sale.purchased_at)} ·{' '}
                                        {sale.attribution_status.replaceAll(
                                            '_',
                                            ' ',
                                        )}{' '}
                                        · Customer:{' '}
                                        {sale.is_new_customer === null
                                            ? 'unknown'
                                            : sale.is_new_customer
                                              ? 'new'
                                              : 'returning'}
                                    </p>
                                    {sale.landing_url && (
                                        <p className="text-xs break-all">
                                            {sale.landing_url}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        Evidence: {sale.evidence}
                                    </p>
                                </div>
                                {canManage &&
                                    sources.some(
                                        (s) =>
                                            s.id === sale.source_id &&
                                            s.kind === 'manual' &&
                                            s.is_enabled,
                                    ) && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setEditing(sale)}
                                        >
                                            Correct sale
                                        </Button>
                                    )}
                            </div>
                        ))}
                        <Pagination page={records} />
                    </CardContent>
                </Card>
            </WorkspacePage>
        </>
    );
}

function SourceCard({
    source,
    canManage,
}: {
    source: Source;
    canManage: boolean;
}) {
    const form = useForm({ action: 'verify', transaction_id: '', note: '' });
    const action = (value: string) => {
        form.transform(() => ({ action: value }));
        form.patch(`/purchases/sources/${source.id}`, { preserveScroll: true });
    };

    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle className="flex flex-wrap gap-2">
                    {source.name}
                    {source.is_primary && <Badge>Primary</Badge>}
                    <Badge variant="outline">
                        {source.is_enabled ? 'Active' : 'Paused'}
                    </Badge>
                </CardTitle>
                <CardDescription>
                    {source.kind === 'manual'
                        ? 'Manual reconciliation'
                        : 'Signed payment feed'}{' '}
                    · {source.verified_at ? 'Owner verified' : 'Not verified'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <dl className="space-y-1 text-xs text-muted-foreground">
                    <div>
                        Collection start: {date(source.tracking_started_at)}
                    </div>
                    <div>First received: {date(source.first_received_at)}</div>
                    <div>Last received: {date(source.last_received_at)}</div>
                    <div>Verified: {date(source.verified_at)}</div>
                </dl>
                {source.endpoint && canManage && (
                    <details className="text-sm">
                        <summary className="cursor-pointer font-medium">
                            Connection instructions
                        </summary>
                        <p className="mt-2">
                            Give your website operator this endpoint and the
                            connection key. The payment source signs each
                            receipt; browser visits and booking buttons must not
                            send purchases.
                        </p>
                        <p className="my-2 rounded-md bg-muted p-2 font-mono text-xs break-all">
                            {source.endpoint}
                        </p>
                        <a
                            className="underline"
                            href="/purchase-integration.md"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Read the integration contract
                        </a>
                    </details>
                )}
                {source.verification_note && (
                    <p className="text-xs text-muted-foreground">
                        {source.verification_note}
                    </p>
                )}
                {canManage && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {!source.is_primary && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => action('primary')}
                                >
                                    Use for totals
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() =>
                                    action(
                                        source.is_enabled ? 'pause' : 'resume',
                                    )
                                }
                            >
                                {source.is_enabled
                                    ? 'Pause collection'
                                    : 'Resume collection'}
                            </Button>
                            {source.kind === 'webhook' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={form.processing}
                                    onClick={() => action('rotate')}
                                >
                                    Rotate key
                                </Button>
                            )}
                        </div>
                        {source.first_received_at && source.is_enabled && (
                            <details className="text-sm">
                                <summary className="cursor-pointer font-medium">
                                    Verify against your payment ledger
                                </summary>
                                <form
                                    className="mt-3 space-y-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        form.transform((data) => ({
                                            ...data,
                                            action: 'verify',
                                        }));
                                        form.patch(
                                            `/purchases/sources/${source.id}`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    <Label htmlFor={`verify-${source.id}`}>
                                        Received completed transaction ID
                                    </Label>
                                    <Input
                                        id={`verify-${source.id}`}
                                        value={form.data.transaction_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'transaction_id',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                    <Label htmlFor={`note-${source.id}`}>
                                        What matched: amount, currency, sale
                                        status and correction/refund handling
                                    </Label>
                                    <Input
                                        id={`note-${source.id}`}
                                        value={form.data.note}
                                        onChange={(e) =>
                                            form.setData('note', e.target.value)
                                        }
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Confirm only after comparing this record
                                        with the real ledger. Leave customer
                                        names and contact details out.
                                    </p>
                                    <InputError
                                        message={Object.values(
                                            form.errors,
                                        ).join(' ')}
                                    />
                                    <Button
                                        size="sm"
                                        disabled={form.processing}
                                    >
                                        Confirm reconciliation
                                    </Button>
                                </form>
                            </details>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function ManualRecord({
    sources,
    sale,
    onDone,
}: {
    sources: Source[];
    sale: Sale | null;
    onDone: () => void;
}) {
    const form = useForm({
        source_id: sale?.source_id ?? sources[0]?.id ?? '',
        transaction_id: sale?.transaction_id ?? '',
        revision: sale ? sale.revision + 1 : 1,
        status: sale?.status ?? 'paid',
        amount_minor: sale?.amount_minor ?? 0,
        refunded_minor: sale?.refunded_minor ?? 0,
        currency: sale?.currency ?? 'EUR',
        purchased_at: sale?.purchased_at?.slice(0, 16) ?? '',
        is_new_customer:
            sale?.is_new_customer === null || !sale
                ? 'unknown'
                : sale.is_new_customer
                  ? 'new'
                  : 'returning',
        item_id: sale?.items[0]?.item_id ?? '',
        item_name: sale?.items[0]?.item_name ?? '',
        evidence: '',
    });

    if (sources.length === 0) {
        return (
            <Card className={workspacePanelClass}>
                <CardHeader>
                    <CardTitle>Manual reconciliation</CardTitle>
                    <CardDescription>
                        Add a manual source to record sales that cannot be
                        connected automatically. These purchases stay explicitly
                        unattributed.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <Card className={workspacePanelClass}>
            <CardHeader>
                <CardTitle>
                    {sale ? 'Correct a sale' : 'Reconcile a sale manually'}
                </CardTitle>
                <CardDescription>
                    Use actual ledger values. Amounts use the currency’s minor
                    unit: cents for EUR/USD. Reuse the transaction ID when
                    correcting a sale.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            source_id: data.source_id || sources[0].id,
                            transaction_id: data.transaction_id,
                            revision: data.revision,
                            status: data.status,
                            amount_minor: data.amount_minor,
                            refunded_minor: data.refunded_minor,
                            currency: data.currency,
                            purchased_at: data.purchased_at
                                ? new Date(
                                      `${data.purchased_at}Z`,
                                  ).toISOString()
                                : null,
                            is_new_customer:
                                data.is_new_customer === 'unknown'
                                    ? null
                                    : data.is_new_customer === 'new',
                            items: sale?.items ?? [
                                {
                                    item_id: data.item_id,
                                    item_name: data.item_name,
                                    quantity: 1,
                                },
                            ],
                            evidence: data.evidence,
                        }));
                        form.post('/purchases/records', {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                onDone();
                            },
                        });
                    }}
                >
                    <Label htmlFor="manual-source">Source</Label>
                    <select
                        id="manual-source"
                        className={selectClass}
                        value={form.data.source_id || sources[0].id}
                        onChange={(e) =>
                            form.setData('source_id', e.target.value)
                        }
                        disabled={sale !== null}
                    >
                        {sources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="transaction">Transaction ID</Label>
                            <Input
                                id="transaction"
                                value={form.data.transaction_id}
                                onChange={(e) =>
                                    form.setData(
                                        'transaction_id',
                                        e.target.value,
                                    )
                                }
                                required
                                readOnly={sale !== null}
                            />
                        </div>
                        <div>
                            <Label htmlFor="revision">Revision</Label>
                            <Input
                                id="revision"
                                type="number"
                                min={1}
                                value={form.data.revision}
                                onChange={(e) =>
                                    form.setData(
                                        'revision',
                                        Number(e.target.value),
                                    )
                                }
                                required
                            />
                        </div>
                    </div>
                    <Label htmlFor="sale-status">Sale status</Label>
                    <select
                        id="sale-status"
                        className={selectClass}
                        value={form.data.status}
                        onChange={(e) => {
                            form.setData('status', e.target.value);

                            if (
                                ['partially_paid', 'unpaid'].includes(
                                    e.target.value,
                                )
                            ) {
                                form.setData('purchased_at', '');
                            }
                        }}
                    >
                        <option value="paid">Completed and paid</option>
                        <option value="partially_paid">Partially paid</option>
                        <option value="unpaid">Unpaid booking</option>
                        <option value="refunded">Fully refunded</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="reconciliation_required">
                            Ledger discrepancy — reconciliation required
                        </option>
                    </select>
                    <div className="grid grid-cols-3 gap-3">
                        <div>
                            <Label htmlFor="amount">
                                Received (minor units)
                            </Label>
                            <Input
                                id="amount"
                                type="number"
                                min={0}
                                value={form.data.amount_minor}
                                onChange={(e) =>
                                    form.setData(
                                        'amount_minor',
                                        Number(e.target.value),
                                    )
                                }
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="refund">
                                Refunded (minor units)
                            </Label>
                            <Input
                                id="refund"
                                type="number"
                                min={0}
                                value={form.data.refunded_minor}
                                onChange={(e) =>
                                    form.setData(
                                        'refunded_minor',
                                        Number(e.target.value),
                                    )
                                }
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="currency">Currency</Label>
                            <Input
                                id="currency"
                                maxLength={3}
                                value={form.data.currency}
                                onChange={(e) =>
                                    form.setData(
                                        'currency',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                required
                            />
                        </div>
                    </div>
                    <Label htmlFor="purchase-date">
                        Completed purchase date and time (UTC)
                    </Label>
                    <Input
                        id="purchase-date"
                        type="datetime-local"
                        value={form.data.purchased_at}
                        onChange={(e) =>
                            form.setData('purchased_at', e.target.value)
                        }
                        required={['paid', 'refunded'].includes(
                            form.data.status,
                        )}
                    />
                    <Label htmlFor="customer-status">Customer history</Label>
                    <select
                        id="customer-status"
                        className={selectClass}
                        value={form.data.is_new_customer}
                        onChange={(e) =>
                            form.setData('is_new_customer', e.target.value)
                        }
                    >
                        <option value="unknown">Unknown</option>
                        <option value="new">Confirmed first purchase</option>
                        <option value="returning">
                            Confirmed returning customer
                        </option>
                    </select>
                    {!sale && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="item-id">Service ID</Label>
                                <Input
                                    id="item-id"
                                    value={form.data.item_id}
                                    onChange={(e) =>
                                        form.setData('item_id', e.target.value)
                                    }
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="item-name">Service name</Label>
                                <Input
                                    id="item-name"
                                    value={form.data.item_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'item_name',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                        </div>
                    )}
                    <Label htmlFor="evidence">
                        Ledger reference and reason for this record/correction
                    </Label>
                    <Input
                        id="evidence"
                        value={form.data.evidence}
                        onChange={(e) =>
                            form.setData('evidence', e.target.value)
                        }
                        required
                    />
                    <p className="text-xs text-muted-foreground">
                        Use references only. Do not enter customer names, email
                        addresses or payment details.
                    </p>
                    <InputError
                        message={Object.values(form.errors).join(' ')}
                    />
                    <div className="flex gap-2">
                        <Button disabled={form.processing}>
                            Save reconciliation
                        </Button>
                        {sale && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onDone}
                            >
                                Cancel correction
                            </Button>
                        )}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

Purchases.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/home' },
        { title: 'Purchases', href: '/purchases' },
    ],
};
