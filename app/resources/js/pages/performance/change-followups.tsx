import { Link } from '@inertiajs/react';
import { PageOutcomeReview } from '@/components/page-outcome-review';
import type { OutcomeContext } from '@/components/page-outcome-review';
import { Badge } from '@/components/ui/badge';
import { workspacePanelClass } from '@/components/workspace-page';

export type SearchEvidence = {
    from: string;
    to: string;
    days: number;
    status: string;
    reason: string | null;
    impressions: number | null;
    clicks: number | null;
    observed_days: number;
    observed_page_days: number;
    expected_page_days: number;
    sparse: boolean;
    last_successful_at: string | null;
};
type PurchaseEvidence = {
    status: string;
    source_name: string | null;
    from: string;
    to: string;
    currencies:
        | {
              currency: string;
              completed_sales: number;
              fully_refunded_sales: number;
              cancelled_sales: number;
              received_minor: number;
              refunded_minor: number;
              net_minor: number;
              new_customer_sales: number;
              returning_customer_sales: number;
              unknown_customer_sales: number;
          }[]
        | null;
    limitations: string[];
};
type Comparison = {
    page_id: string;
    title: string;
    status: string;
    reason: string | null;
    baseline: SearchEvidence | null;
    followup: SearchEvidence | null;
    comparison_available: boolean;
};
type Review = {
    search: SearchEvidence;
    purchases: PurchaseEvidence;
    baseline_reconciled_purchases: PurchaseEvidence;
    all_recorded_purchases: PurchaseEvidence;
    search_comparison_available: boolean;
    purchase_comparison_available: boolean;
    comparisons: Comparison[];
    wider_search: SearchEvidence;
    wider_scope: string;
    limitations: string[];
};
type FollowupPeriod = {
    current_recoveries?: { status: string; at: string | null }[];
    id: string;
    days: number;
    from: string;
    to: string;
    due_on: string;
    settled_days: number;
    status: string;
    captured_at: string | null;
    review_count: number;
    review: Review | null;
    baseline: {
        search: SearchEvidence;
        purchases: PurchaseEvidence;
        all_recorded_purchases: PurchaseEvidence;
    } | null;
    baseline_wider_search: SearchEvidence | null;
};
export type ChangeReport = {
    items: {
        outcome: OutcomeContext;
        publication_id: string;
        page_id: string;
        title: string;
        verified_at: string;
        baseline_pinned_at: string;
        baseline_stage: string;
        known_confounders: string[];
        comparison_rule: string;
        periods: FollowupPeriod[];
    }[];
    total_windows: number;
    limit: number;
    method: string;
};
const count = (value: number | null | undefined) =>
    value == null ? 'Not observed' : value.toLocaleString();
const when = (value: string | null) =>
    value ? new Date(value).toLocaleString() : 'Not recorded';
const state: Record<string, string> = {
    observing: 'Observation underway',
    awaiting_data: 'Ready for a data read',
    observed: 'Observations recorded',
    sparse: 'Limited observations',
    partial: 'Incomplete read',
    unavailable: 'Evidence unavailable',
    recorded: 'Recorded',
    failed: 'Read failed',
    reading: 'Reading',
};

export function ChangeFollowups({ report }: { report: ChangeReport }) {
    return (
        <section
            className={`${workspacePanelClass} p-5 sm:p-6`}
            aria-labelledby="change-followups-title"
        >
            <h2 id="change-followups-title" className="text-lg font-semibold">
                Published changes
            </h2>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                {report.method}
            </p>
            {report.items.length === 0 && (
                <p className="mt-4 text-sm text-muted-foreground">
                    After an approved page change is verified on your website,
                    its saved baseline and 14-day and 28-day follow-ups will
                    appear here.
                </p>
            )}
            {report.items.map((change) => (
                <article
                    key={change.publication_id}
                    className="mt-6 border-t pt-5"
                >
                    <Link
                        className="font-semibold hover:underline"
                        href={`/pages/${change.page_id}`}
                    >
                        {change.title}
                    </Link>
                    <p className="mt-2 text-xs leading-6 text-muted-foreground">
                        Verified live: {when(change.verified_at)} · Baseline
                        saved: {when(change.baseline_pinned_at)}
                    </p>
                    {change.baseline_stage !== 'approval' && (
                        <p className="mt-2 text-sm text-amber-700 dark:text-amber-400">
                            No baseline was saved at approval. Historical
                            results cannot be reconstructed as approval
                            evidence.
                        </p>
                    )}
                    {change.known_confounders.length > 0 && (
                        <p className="mt-2 text-sm">
                            Other known influences:{' '}
                            {change.known_confounders.join('; ')}
                        </p>
                    )}
                    <div className="mt-4 grid gap-4 xl:grid-cols-2">
                        {change.periods.map((period) => (
                            <Period
                                key={period.id}
                                period={
                                    period.current_recoveries?.length &&
                                    period.review
                                        ? {
                                              ...period,
                                              review: {
                                                  ...period.review,
                                                  search_comparison_available: false,
                                                  purchase_comparison_available: false,
                                                  limitations: [
                                                      ...period.review
                                                          .limitations,
                                                      'A recovery or unresolved recovery attempt is now recorded for this interval. Original observations remain retained; comparable effects are unavailable.',
                                                  ],
                                              },
                                          }
                                        : period
                                }
                                rule={change.comparison_rule}
                            />
                        ))}
                    </div>
                    <PageOutcomeReview outcome={change.outcome} />
                </article>
            ))}
            {report.total_windows > report.limit && (
                <p className="mt-4 text-xs text-muted-foreground">
                    Showing the latest {report.limit} observation windows of{' '}
                    {report.total_windows} retained windows.
                </p>
            )}
        </section>
    );
}

function Period({ period, rule }: { period: FollowupPeriod; rule: string }) {
    const review = period.review;

    return (
        <section className="rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold">{period.days}-day follow-up</h3>
                <Badge variant="secondary">
                    {state[period.status] ?? period.status}
                </Badge>
            </div>
            <p className="mt-3 text-xs leading-6 text-muted-foreground">
                {period.from} – {period.to} · Pacific dates
                <br />
                {period.settled_days} / {period.days} settled days · Review from{' '}
                {period.due_on}
            </p>
            {review === null && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {period.settled_days < period.days
                        ? 'The observation period is still settling. Results are not ready.'
                        : 'Use Read latest data to save the available evidence for this window.'}
                </p>
            )}
            <SearchComparison
                before={period.baseline?.search ?? null}
                after={review?.search ?? null}
            />
            {review && !review.search_comparison_available && (
                <p className="mt-2 text-xs text-muted-foreground">
                    The available evidence does not support a reliable
                    before/after comparison. Sparse observations may need a
                    longer review period.
                </p>
            )}
            <details className="mt-4 border-t pt-3">
                <summary className="cursor-pointer text-sm font-medium">
                    Confirmed purchase evidence
                </summary>
                <Purchases
                    label="Baseline as saved at approval"
                    evidence={period.baseline?.purchases ?? null}
                />
                <Purchases
                    label="After verification"
                    evidence={review?.purchases ?? null}
                />
                {review && (
                    <Purchases
                        label="Baseline with later corrections and refunds"
                        evidence={review.baseline_reconciled_purchases}
                    />
                )}
                {review && !review.purchase_comparison_available && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        Purchase evidence is incomplete, unavailable or not
                        comparable. No missing records are treated as zero
                        purchases.
                    </p>
                )}
                <p className="mt-3 text-xs text-muted-foreground">
                    These are the pinned sale source’s records with known
                    landing-page attribution. Analytics events are never added.
                    Sale cohorts include later refunds; they are not cash flow
                    during this period.
                </p>
                <details className="mt-3">
                    <summary className="cursor-pointer text-xs font-medium">
                        All recorded purchases from this source
                    </summary>
                    <Purchases
                        label="Approval baseline · all landing contexts"
                        evidence={
                            period.baseline?.all_recorded_purchases ?? null
                        }
                    />
                    <Purchases
                        label="Follow-up · all landing contexts"
                        evidence={review?.all_recorded_purchases ?? null}
                    />
                    <p className="mt-2 text-xs text-muted-foreground">
                        Includes unknown and other landing contexts. These
                        totals are not attributed to the changed page.
                    </p>
                </details>
            </details>
            <details className="mt-4 border-t pt-3">
                <summary className="cursor-pointer text-sm font-medium">
                    Comparison pages and wider context
                </summary>
                <p className="mt-3 text-xs leading-5 text-muted-foreground">
                    {rule}
                </p>
                {(review?.comparisons ?? []).map((comparison) => (
                    <div
                        key={comparison.page_id}
                        className="mt-4 border-t pt-3"
                    >
                        <Link
                            className="text-sm font-medium hover:underline"
                            href={`/pages/${comparison.page_id}`}
                        >
                            {comparison.title}
                        </Link>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {comparison.reason ??
                                'Available public snapshots agree; no intervening change was recorded.'}
                        </p>
                        <SearchComparison
                            before={comparison.baseline}
                            after={comparison.followup}
                        />
                        {!comparison.comparison_available && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                This comparison is not established by the
                                available evidence.
                            </p>
                        )}
                    </div>
                ))}
                {(!review || review.comparisons.length === 0) && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        No verified unchanged comparison is available.
                    </p>
                )}
                <h4 className="mt-5 text-sm font-medium">
                    Other pages tracked at approval
                </h4>
                <SearchComparison
                    before={period.baseline_wider_search}
                    after={review?.wider_search ?? null}
                    cohort
                />
                <p className="mt-2 text-xs text-muted-foreground">
                    {review?.wider_scope ??
                        'The same fixed group of other tracked pages is used in both periods. This is not a whole-site total.'}
                </p>
            </details>
            {review && (
                <details className="mt-4 border-t pt-3">
                    <summary className="cursor-pointer text-sm font-medium">
                        Limits and observation history
                    </summary>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Latest saved observation: {when(period.captured_at)} ·{' '}
                        {period.review_count} retained versions
                    </p>
                    {review.limitations.map((limit) => (
                        <p
                            key={limit}
                            className="mt-2 text-xs leading-5 text-muted-foreground"
                        >
                            {limit}
                        </p>
                    ))}
                </details>
            )}
        </section>
    );
}
function SearchComparison({
    before,
    after,
    cohort = false,
}: {
    before: SearchEvidence | null;
    after: SearchEvidence | null;
    cohort?: boolean;
}) {
    return (
        <div className="mt-3 overflow-x-auto">
            <table className="w-full min-w-[290px] text-xs">
                <thead>
                    <tr className="border-b text-left text-muted-foreground">
                        <th className="py-2">Search observations</th>
                        <th>Baseline</th>
                        <th>Follow-up</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th className="py-2 text-left font-normal">
                            Impressions
                        </th>
                        <td>{count(before?.impressions)}</td>
                        <td>{count(after?.impressions)}</td>
                    </tr>
                    <tr>
                        <th className="py-2 text-left font-normal">Clicks</th>
                        <td>{count(before?.clicks)}</td>
                        <td>{count(after?.clicks)}</td>
                    </tr>
                    <tr>
                        <th className="py-2 text-left font-normal">
                            {cohort ? 'Observed page-days' : 'Observed days'}
                        </th>
                        <td>
                            {before
                                ? `${cohort ? before.observed_page_days : before.observed_days} / ${cohort ? before.expected_page_days : before.days}`
                                : 'Not observed'}
                        </td>
                        <td>
                            {after
                                ? `${cohort ? after.observed_page_days : after.observed_days} / ${cohort ? after.expected_page_days : after.days}`
                                : 'Not observed'}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p className="mt-2 text-xs leading-5 text-muted-foreground">
                Baseline:{' '}
                {before
                    ? `${before.from} – ${before.to} · ${state[before.status] ?? before.status}`
                    : 'Unavailable'}
                <br />
                Follow-up read: {when(after?.last_successful_at ?? null)}
                {after?.reason && ` · ${after.reason}`}
            </p>
        </div>
    );
}
function Purchases({
    label,
    evidence,
}: {
    label: string;
    evidence: PurchaseEvidence | null;
}) {
    return (
        <div className="mt-4 text-xs leading-6">
            <h4 className="font-medium">{label}</h4>
            <p className="text-muted-foreground">
                {evidence?.source_name ?? 'No purchase source'} ·{' '}
                {evidence?.status ?? 'unavailable'}
            </p>
            {!evidence?.currencies?.length && <p>Not observed.</p>}
            {evidence?.currencies?.map((row) => (
                <p key={row.currency}>
                    {row.completed_sales} completed sales ·{' '}
                    {money(row.received_minor, row.currency)} received ·{' '}
                    {money(row.refunded_minor, row.currency)} refunded ·{' '}
                    {money(row.net_minor, row.currency)} net
                    <br />
                    {row.fully_refunded_sales} fully refunded ·{' '}
                    {row.cancelled_sales} cancelled
                    <br />
                    {row.new_customer_sales} sales marked new customer ·{' '}
                    {row.returning_customer_sales} returning ·{' '}
                    {row.unknown_customer_sales} customer status unknown
                </p>
            ))}
            {evidence?.limitations.map((limit) => (
                <p key={limit} className="mt-1 text-muted-foreground">
                    {limit}
                </p>
            ))}
        </div>
    );
}
function money(minor: number, currency: string) {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });

    return formatter.format(
        minor / 10 ** (formatter.resolvedOptions().maximumFractionDigits ?? 2),
    );
}
