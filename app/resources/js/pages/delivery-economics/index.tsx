import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Entry = {
    id: string;
    category: string;
    minutes: number;
    hourly_usd_cents: number | null;
    happened_at: string;
    note: string;
    supersedes_id: string | null;
    replaced_by: string | null;
};
type Report = {
    start: string;
    end_exclusive: string;
    provider: {
        total_micros: number;
        completeness: string;
        unknown_provider_attempts: number;
        ambiguous_provider_records: number;
        limitations: string[];
    };
    support: {
        entries: number;
        minutes: number;
        priced_micros: number;
        unpriced_minutes: number;
        categories: Record<string, number>;
    };
    owner_review: { active_seconds: number; decisions: Record<string, number> };
    improvements: number;
    offer: {
        price_cents: number;
        currency: string;
        allowance: number;
        name: string;
        articles: number;
        remaining_after_recorded_costs_micros: number | null;
    };
    history: Entry[];
};
const usd = (micros: number) =>
    new Intl.NumberFormat('en', { style: 'currency', currency: 'USD' }).format(
        micros / 1_000_000,
    );
const localTime = (value = new Date()) =>
    new Date(value.getTime() - value.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);

export default function DeliveryEconomics({
    month,
    report,
    categories,
}: {
    month: string;
    report: Report;
    categories: string[];
}) {
    const [selectedMonth, setSelectedMonth] = useState(month);
    const requestId = useRef<string | null>(null);
    const form = useForm({
        category: 'support',
        minutes: '',
        hourly_usd_cents: '',
        happened_at: '',
        note: '',
        supersedes_id: '',
    });
    const correct = (entry: Entry) => {
        requestId.current = null;
        form.setData({
            category: entry.category,
            minutes: String(entry.minutes),
            hourly_usd_cents:
                entry.hourly_usd_cents === null
                    ? ''
                    : String(entry.hourly_usd_cents / 100),
            happened_at: localTime(new Date(entry.happened_at)),
            note: '',
            supersedes_id: entry.id,
        });
        document
            .getElementById('effort-form')
            ?.scrollIntoView({ behavior: 'smooth' });
    };

    return (
        <>
            <Head title="Delivery costs" />
            <div className="mx-auto w-full max-w-5xl space-y-8 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Delivery costs</h1>
                    <p className="mt-2 text-muted-foreground">
                        Owner-only records for assessing the monthly package.
                        Log actual operator work separately from provider
                        charges.
                    </p>
                    <Link
                        className="mt-2 inline-block underline"
                        href="/billing"
                    >
                        Plan and usage
                    </Link>
                </div>
                {report.provider.completeness !== 'complete' && (
                    <div className="rounded-lg border border-amber-500 p-4">
                        <p className="font-medium">
                            Provider cost is incomplete
                        </p>
                        <p>
                            {report.provider.unknown_provider_attempts} attempts
                            have unknown charges;{' '}
                            {report.provider.ambiguous_provider_records} records
                            need reconciliation.
                        </p>
                        {report.provider.limitations.map((limitation) => (
                            <p key={limitation} className="mt-1 text-sm">
                                {limitation}
                            </p>
                        ))}
                    </div>
                )}
                <form
                    className="flex max-w-sm items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/delivery-economics', {
                            month: selectedMonth,
                        });
                    }}
                >
                    <div>
                        <Label htmlFor="cost-month">Calendar month (UTC)</Label>
                        <Input
                            id="cost-month"
                            type="month"
                            value={selectedMonth}
                            onChange={(event) =>
                                setSelectedMonth(event.target.value)
                            }
                            required
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        View
                    </Button>
                </form>
                <p className="text-sm text-muted-foreground">
                    {report.start} to {report.end_exclusive}, ending
                    exclusively. This calendar window is separate from
                    subscription billing periods.
                </p>
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border p-4">
                        <p>Recorded provider cost</p>
                        <p className="text-2xl font-semibold">
                            {usd(report.provider.total_micros)}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Metered calls, including recovered receipts.
                            Incomplete or unknown charges are not zero.
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p>Operator time</p>
                        <p className="text-2xl font-semibold">
                            {report.support.entries === 0
                                ? 'Not recorded'
                                : `${report.support.minutes} min`}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {usd(report.support.priced_micros)} priced;{' '}
                            {report.support.unpriced_minutes} minutes without a
                            rate. Recording completeness is not assumed.
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p>Owner review timer</p>
                        <p className="text-2xl font-semibold">
                            {Math.round(
                                report.owner_review.active_seconds / 60,
                            )}{' '}
                            min
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Submitted review intervals only. Kept separate; not
                            added to operator cost.
                        </p>
                    </div>
                </div>
                <div className="space-y-2 rounded-lg border p-4">
                    <h2 className="font-semibold">
                        {report.offer.name} package scenario
                    </h2>
                    <p>
                        US${report.offer.price_cents / 100} per month for{' '}
                        {report.offer.articles} articles and{' '}
                        {report.offer.allowance} first-accepted page
                        improvements. {report.improvements} allowance units
                        first accepted in this calendar window.
                    </p>
                    <p>
                        Remaining after recorded costs:{' '}
                        <strong>
                            {report.offer
                                .remaining_after_recorded_costs_micros === null
                                ? 'Unknown'
                                : usd(
                                      report.offer
                                          .remaining_after_recorded_costs_micros,
                                  )}
                        </strong>
                        .
                    </p>
                    <p className="text-sm text-muted-foreground">
                        This is a price scenario, not revenue received or
                        profit. It excludes unrecorded work, unpriced or missing
                        provider charges, hosting, payment fees, taxes and other
                        overhead. A partial month is not projected into a full
                        month. No sustainable margin is claimed.
                    </p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <h2 className="font-semibold">
                            Recorded operator work
                        </h2>
                        {Object.entries(report.support.categories).length ===
                        0 ? (
                            <p className="text-muted-foreground">
                                No entries in this month.
                            </p>
                        ) : (
                            Object.entries(report.support.categories).map(
                                ([category, minutes]) => (
                                    <p key={category} className="capitalize">
                                        {category}: {minutes} min
                                    </p>
                                ),
                            )
                        )}
                    </div>
                    <div>
                        <h2 className="font-semibold">Review actions</h2>
                        {Object.entries(report.owner_review.decisions)
                            .length === 0 ? (
                            <p className="text-muted-foreground">
                                No review actions recorded.
                            </p>
                        ) : (
                            Object.entries(report.owner_review.decisions).map(
                                ([action, count]) => (
                                    <p key={action}>
                                        {action.replaceAll('_', ' ')}: {count}
                                    </p>
                                ),
                            )
                        )}
                        <p className="text-sm text-muted-foreground">
                            Actions can repeat for one proposal; these counts
                            are not an acceptance rate.
                        </p>
                    </div>
                </div>
                <form
                    id="effort-form"
                    className="space-y-4 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        requestId.current ??= crypto.randomUUID();
                        form.transform((data) => ({
                            ...data,
                            request_id: requestId.current,
                            minutes: Number(data.minutes),
                            hourly_usd_cents:
                                data.hourly_usd_cents === ''
                                    ? null
                                    : Math.round(
                                          Number(data.hourly_usd_cents) * 100,
                                      ),
                            happened_at: new Date(data.happened_at)
                                .toISOString()
                                .replace('.000Z', '+00:00'),
                            supersedes_id: data.supersedes_id || null,
                        }));
                        form.post('/delivery-economics', {
                            preserveScroll: true,
                            onSuccess: () => {
                                requestId.current = null;
                                form.reset();
                            },
                        });
                    }}
                >
                    <h2 className="font-semibold">
                        {form.data.supersedes_id
                            ? 'Correct recorded work'
                            : 'Record operator work'}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Use measured time and an actual agreed USD cost rate
                        when known. Leave the rate blank if unknown. Do not copy
                        the owner review timer into this record unless it is
                        actual operator work you have not already logged.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="effort-category">Work</Label>
                            <select
                                id="effort-category"
                                className="mt-1 w-full rounded-md border bg-background p-2"
                                value={form.data.category}
                                onChange={(event) =>
                                    form.setData('category', event.target.value)
                                }
                            >
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="effort-minutes">
                                Actual minutes
                            </Label>
                            <Input
                                id="effort-minutes"
                                type="number"
                                min={form.data.supersedes_id ? 0 : 1}
                                max={1440}
                                step={1}
                                required
                                value={form.data.minutes}
                                onChange={(event) =>
                                    form.setData('minutes', event.target.value)
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="effort-rate">
                                USD cost per hour (optional)
                            </Label>
                            <Input
                                id="effort-rate"
                                type="number"
                                min={0}
                                max={1000}
                                step="0.01"
                                value={form.data.hourly_usd_cents}
                                onChange={(event) =>
                                    form.setData(
                                        'hourly_usd_cents',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="effort-date">
                                When the work happened (your local time)
                            </Label>
                            <Input
                                id="effort-date"
                                type="datetime-local"
                                required
                                value={form.data.happened_at}
                                onChange={(event) =>
                                    form.setData(
                                        'happened_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>
                    <div>
                        <Label htmlFor="effort-note">
                            Work completed, or reason for correction
                        </Label>
                        <Textarea
                            id="effort-note"
                            required
                            maxLength={2000}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </div>
                    {form.data.supersedes_id && (
                        <p className="text-sm">
                            Replacing {form.data.supersedes_id}. Zero minutes
                            voids its counted work; the original remains
                            visible.
                        </p>
                    )}
                    {Object.values(form.errors).map((error) => (
                        <p
                            key={error}
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    <div className="flex gap-2">
                        <Button disabled={form.processing}>
                            Save work record
                        </Button>
                        {form.data.supersedes_id && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    requestId.current = null;
                                    form.reset();
                                }}
                            >
                                Cancel correction
                            </Button>
                        )}
                    </div>
                </form>
                <div className="space-y-3">
                    <h2 className="text-lg font-semibold">
                        Latest 100 records across all months
                    </h2>
                    {report.history.length === 0 && (
                        <p className="text-muted-foreground">
                            No operator work has been recorded.
                        </p>
                    )}
                    {report.history.map((entry) => (
                        <div
                            key={entry.id}
                            className="space-y-1 rounded-lg border p-4"
                        >
                            <p className="font-medium">
                                {entry.category} · {entry.minutes} min ·{' '}
                                {new Date(entry.happened_at).toLocaleString()}
                            </p>
                            <p>
                                {entry.hourly_usd_cents === null
                                    ? 'Rate unknown'
                                    : `${usd(entry.hourly_usd_cents * 10000)}/hour`}
                            </p>
                            <p className="whitespace-pre-wrap">{entry.note}</p>
                            {entry.supersedes_id && (
                                <p className="text-sm text-muted-foreground">
                                    Corrects {entry.supersedes_id}
                                </p>
                            )}
                            {entry.replaced_by ? (
                                <p className="text-sm text-muted-foreground">
                                    Replaced by {entry.replaced_by}; excluded
                                    from totals.
                                </p>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => correct(entry)}
                                >
                                    Record correction
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

DeliveryEconomics.layout = {
    breadcrumbs: [{ title: 'Delivery costs', href: '/delivery-economics' }],
};
