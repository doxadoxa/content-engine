import { Link, useForm, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type OutcomeContext = {
    publication_id: string;
    verified: boolean;
    recovered_at: string | null;
    latest_id: string | null;
    measurement_review_id: string | null;
    periods: {
        days: number;
        current_recoveries: { status: string }[];
        status: string;
        due_on: string;
        baseline: { search?: { clicks: number | null } } | null;
        review: {
            search?: { clicks: number | null };
            search_comparison_available?: boolean;
        } | null;
    }[];
    history: {
        id: string;
        decision: string;
        reason: string;
        result: string;
        next_opportunity_id: string | null;
        created_at: string;
    }[];
};
const decisions = {
    keep_observing: 'Keep observing',
    leave_unchanged: 'Leave the page unchanged',
    reassess: 'Reassess the next opportunity',
};

export function PageOutcomeReview({ outcome }: { outcome: OutcomeContext }) {
    const { auth } = usePage().props;
    const form = useForm({
        decision: 'keep_observing',
        reason: '',
        expected_outcome_id: outcome.latest_id,
        measurement_review_id: outcome.measurement_review_id,
    });

    if (!outcome.verified) {
        return null;
    }

    return (
        <div className="mt-5 border-t pt-5">
            <h3 className="font-semibold">Review this change’s outcome</h3>
            <p className="mt-2 text-sm text-muted-foreground">
                Use the measured window and your observations to choose the next
                step. A dip alone does not justify rewriting. Reassessment only
                adds a supported opportunity to Plan.
            </p>
            {outcome.recovered_at && (
                <p className="mt-3 text-sm text-amber-700 dark:text-amber-400">
                    This change was recovered on{' '}
                    {new Date(outcome.recovered_at).toLocaleString()}. Its
                    original effect cannot be isolated across a recovery.
                </p>
            )}
            <div className="mt-3 space-y-2 text-xs text-muted-foreground">
                {outcome.periods.map((period) => (
                    <p key={period.days}>
                        {period.days}-day follow-up:{' '}
                        {period.status.replaceAll('_', ' ')}
                        {period.current_recoveries.length === 0 &&
                        period.review?.search_comparison_available
                            ? ` · ${period.baseline?.search?.clicks ?? 'unavailable'} → ${period.review.search?.clicks ?? 'unavailable'} observed clicks`
                            : ' · comparable search result unavailable'}{' '}
                        · settles {period.due_on}
                    </p>
                ))}
            </div>
            {auth.project?.role === 'owner' && (
                <form
                    className="mt-4 grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            expected_outcome_id: outcome.latest_id,
                            measurement_review_id:
                                outcome.measurement_review_id,
                        }));
                        form.post(
                            `/publications/${outcome.publication_id}/outcome-review`,
                            {
                                preserveScroll: true,
                                onSuccess: () => form.reset('reason'),
                            },
                        );
                    }}
                >
                    <Label htmlFor={`decision-${outcome.publication_id}`}>
                        Next step
                    </Label>
                    <select
                        id={`decision-${outcome.publication_id}`}
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={form.data.decision}
                        onChange={(event) =>
                            form.setData('decision', event.target.value)
                        }
                    >
                        {Object.entries(decisions).map(([value, label]) => (
                            <option
                                key={value}
                                value={value}
                                disabled={
                                    value === 'reassess' &&
                                    !outcome.measurement_review_id
                                }
                            >
                                {label}
                            </option>
                        ))}
                    </select>
                    {!outcome.measurement_review_id && (
                        <p className="text-xs text-muted-foreground">
                            Reassessment becomes available after a settled
                            14-day or 28-day observation is read in Performance.
                        </p>
                    )}
                    <Label htmlFor={`reason-${outcome.publication_id}`}>
                        What did you observe, and why this decision?
                    </Label>
                    <Textarea
                        id={`reason-${outcome.publication_id}`}
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        required
                        minLength={3}
                        maxLength={2000}
                    />
                    {Object.values(form.errors).map((error, index) => (
                        <InputError key={index} message={error} />
                    ))}
                    <Button
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        {form.processing
                            ? 'Recording…'
                            : 'Record outcome decision'}
                    </Button>
                </form>
            )}
            {outcome.history.length > 0 && (
                <details className="mt-4 text-sm">
                    <summary className="cursor-pointer font-medium">
                        Owner outcome decisions ({outcome.history.length})
                    </summary>
                    <div className="mt-3 space-y-4">
                        {outcome.history.map((review) => (
                            <div key={review.id}>
                                <p className="font-medium">
                                    {
                                        decisions[
                                            review.decision as keyof typeof decisions
                                        ]
                                    }{' '}
                                    ·{' '}
                                    {new Date(
                                        review.created_at,
                                    ).toLocaleString()}
                                </p>
                                <p className="mt-1">{review.reason}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {review.result}
                                </p>
                                {review.next_opportunity_id && (
                                    <Link
                                        className="mt-2 inline-block underline"
                                        href="/plan"
                                    >
                                        Review the next opportunity in Plan
                                    </Link>
                                )}
                            </div>
                        ))}
                    </div>
                </details>
            )}
        </div>
    );
}
