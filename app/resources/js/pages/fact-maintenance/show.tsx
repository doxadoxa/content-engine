import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import type { Check, Claim, Fact, Version } from './types';

type Result = {
    assessment: {
        status: string;
        coverage: {
            total_characters?: number;
            assessed_characters?: number;
            attempted_calls?: number;
            validated_calls?: number;
        };
        checker: {
            policy_version: string;
            calls: {
                provider: string;
                model: string;
                input_tokens: number;
                output_tokens: number;
                prompt_hash: string;
            }[];
        };
        limitations: string[];
    };
    source_coverage: {
        capture_status: string;
        omitted: { locator?: string; reason: string }[];
        limitations: string[];
        applicability: string;
    };
    comparisons: {
        previous_claim_id: string;
        exact_quote: string;
        observation: string;
        meaning: string;
        current_candidates?: {
            exactQuote: string;
            relation: string;
            reason: string;
        }[];
    }[];
};

export default function MaintenanceShow({
    check,
    result,
    current_reason,
    facts,
    selected_facts,
    claims,
}: {
    check: Check;
    result: Result | null;
    current_reason: string | null;
    facts: Fact[];
    selected_facts: Version[];
    claims: Claim[];
}) {
    const owner = usePage().props.auth.project?.role === 'owner';
    usePoll(15000, { only: ['check', 'result', 'claims', 'current_reason'] });
    const recheck = useForm({
        request_key: crypto.randomUUID(),
        page_ids: [check.site_page_id],
        fact_version_ids: facts
            .filter(
                (f) =>
                    f.usable &&
                    f.current &&
                    selected_facts.some((old) => old.business_fact_id === f.id),
            )
            .map((f) => f.current!.id),
    });
    const busy = ['queued', 'running'].includes(check.status);

    return (
        <>
            <Head title="Page fact check" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Page fact check"
                    title={check.specification.canonical_url}
                    description={`${check.specification.locale} · ${check.status} · ${new Date(check.created_at).toLocaleString()}`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/fact-maintenance">
                                Maintenance overview
                            </Link>
                        </Button>
                    }
                />
                <section
                    className={`${workspacePanelClass} space-y-3 p-5 text-sm`}
                >
                    <a
                        className="underline"
                        href={check.specification.canonical_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open the source page
                    </a>
                    <p>{check.reason}</p>
                    {!busy && current_reason && (
                        <p className="font-medium">{current_reason}</p>
                    )}
                    <p>
                        Accepted reviews and exports do not publish or verify a
                        page change. A fresh check records new evidence and
                        preserves this history.
                    </p>
                    {owner && (
                        <Button
                            variant="outline"
                            disabled={
                                busy ||
                                recheck.processing ||
                                !recheck.data.fact_version_ids.length
                            }
                            onClick={() => recheck.post('/fact-maintenance')}
                        >
                            Recheck with current confirmed versions
                        </Button>
                    )}
                    {!recheck.data.fact_version_ids.length && (
                        <p>
                            Choose replacement facts in the{' '}
                            <Link
                                href="/fact-maintenance"
                                className="underline"
                            >
                                maintenance overview
                            </Link>
                            . Retracted evidence cannot ground a new correction.
                        </p>
                    )}
                </section>
                <section
                    className={`${workspacePanelClass} space-y-3 p-5 text-sm`}
                >
                    <h2 className="font-semibold">Evidence used at the time</h2>
                    {selected_facts.map((fact) => (
                        <div key={fact.id} className="border-t pt-3">
                            <p>{fact.statement}</p>
                            <p className="text-muted-foreground">
                                {fact.status} · confirmed{' '}
                                {fact.confirmed_at ?? 'never'} · review due{' '}
                                {fact.review_due_at ?? 'unknown'}
                            </p>
                            <p>{fact.source_note}</p>
                            {fact.source_url && (
                                <a
                                    href={fact.source_url}
                                    className="underline"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Fact source
                                </a>
                            )}
                        </div>
                    ))}
                </section>
                {result && (
                    <section
                        className={`${workspacePanelClass} space-y-3 p-5 text-sm`}
                    >
                        <h2 className="font-semibold">
                            Coverage and limitations
                        </h2>
                        <p>
                            {result.assessment.coverage.assessed_characters ??
                                0}{' '}
                            of{' '}
                            {result.assessment.coverage.total_characters ?? 0}{' '}
                            captured characters assessed ·{' '}
                            {result.assessment.coverage.validated_calls ?? 0}{' '}
                            validated responses from{' '}
                            {result.assessment.coverage.attempted_calls ?? 0}{' '}
                            attempts.
                        </p>
                        <p>{result.source_coverage.applicability}</p>
                        {[
                            ...result.source_coverage.limitations,
                            ...result.assessment.limitations,
                        ].map((limit, i) => (
                            <p className="text-muted-foreground" key={i}>
                                {limit}
                            </p>
                        ))}
                        {result.source_coverage.omitted.map((omission, i) => (
                            <p key={i}>
                                Not covered: {omission.locator} —{' '}
                                {omission.reason}
                            </p>
                        ))}
                        <details>
                            <summary className="cursor-pointer">
                                Checker provenance
                            </summary>
                            <p>
                                Policy:{' '}
                                {result.assessment.checker.policy_version}
                            </p>
                            {result.assessment.checker.calls.map((call, i) => (
                                <p className="mt-2 text-xs break-all" key={i}>
                                    {call.provider} · {call.model} ·{' '}
                                    {call.input_tokens} input /{' '}
                                    {call.output_tokens} output tokens · request
                                    hash {call.prompt_hash}
                                </p>
                            ))}
                        </details>
                    </section>
                )}
                {result && claims.length === 0 && (
                    <section className={`${workspacePanelClass} p-5 text-sm`}>
                        No validated claim findings were returned. This does not
                        establish that the page is factually correct; inspect
                        the coverage and limitations above.
                    </section>
                )}
                {claims.map((claim) => (
                    <section
                        key={claim.id}
                        className={`${workspacePanelClass} space-y-3 p-5`}
                    >
                        <h2 className="font-semibold">
                            Proposed {claim.relation} claim
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {claim.source.kind} · {claim.source.locator} ·
                            characters {claim.start_codepoint}–
                            {claim.end_codepoint}
                        </p>
                        <blockquote className="border-l-2 pl-4 text-sm whitespace-pre-wrap">
                            {claim.exact_quote}
                        </blockquote>
                        <p className="text-sm">{claim.reason}</p>
                        {claim.fact_version_id && (
                            <p className="text-sm">
                                Compared with:{' '}
                                {selected_facts.find(
                                    (f) => f.id === claim.fact_version_id,
                                )?.statement ?? 'See preserved evidence.'}
                            </p>
                        )}
                        {claim.reviews.map((review) => (
                            <div
                                className="space-y-2 border-t pt-3 text-sm"
                                key={review.id}
                            >
                                <p>
                                    {review.action} ·{' '}
                                    {new Date(
                                        review.created_at,
                                    ).toLocaleString()}{' '}
                                    · {review.reason}
                                </p>
                                {review.evidence.opportunity_id && (
                                    <Link className="underline" href="/plan">
                                        View the correction opportunity
                                    </Link>
                                )}
                                {review.evidence.handoff && (
                                    <div className="rounded border p-3">
                                        <h3 className="font-medium">
                                            Assisted editing handoff
                                        </h3>
                                        <p>
                                            {review.evidence.handoff.url} ·{' '}
                                            {review.evidence.handoff.locale}
                                        </p>
                                        <p>Find this exact occurrence:</p>
                                        <pre className="whitespace-pre-wrap">
                                            {review.evidence.handoff.before}
                                        </pre>
                                        <p>Owner-reviewed replacement:</p>
                                        <pre className="whitespace-pre-wrap">
                                            {review.evidence.handoff.after}
                                        </pre>
                                        <p>
                                            {
                                                review.evidence.handoff
                                                    .instructions
                                            }
                                        </p>
                                        <p>
                                            {review.evidence.handoff.preserve}
                                        </p>
                                        <p>
                                            {
                                                review.evidence.handoff
                                                    .verification
                                            }
                                        </p>
                                    </div>
                                )}
                            </div>
                        ))}
                        {owner && (
                            <ReviewForm
                                key={claim.reviews[0]?.id ?? claim.id}
                                claim={claim}
                                facts={facts}
                                actionable={
                                    !current_reason &&
                                    !busy &&
                                    ['complete', 'partial'].includes(
                                        check.status,
                                    )
                                }
                            />
                        )}
                    </section>
                ))}
                {!!result?.comparisons.length && (
                    <section
                        className={`${workspacePanelClass} space-y-3 p-5 text-sm`}
                    >
                        <h2 className="font-semibold">
                            Earlier quote comparison
                        </h2>
                        {result.comparisons.map((item) => (
                            <div
                                className="border-t pt-3"
                                key={item.previous_claim_id}
                            >
                                <blockquote>{item.exact_quote}</blockquote>
                                <p>
                                    {item.observation}: {item.meaning}
                                </p>
                                {item.current_candidates?.map(
                                    (candidate, index) => (
                                        <div
                                            key={index}
                                            className="mt-2 border-l-2 pl-3"
                                        >
                                            <blockquote>
                                                {candidate.exactQuote}
                                            </blockquote>
                                            <p>
                                                Proposed {candidate.relation}:{' '}
                                                {candidate.reason}
                                            </p>
                                        </div>
                                    ),
                                )}
                            </div>
                        ))}
                    </section>
                )}
            </WorkspacePage>
        </>
    );
}

function ReviewForm({
    claim,
    facts,
    actionable,
}: {
    claim: Claim;
    facts: Fact[];
    actionable: boolean;
}) {
    const dismissed = claim.reviews[0]?.action === 'dismiss';
    const form = useForm({
        request_key: crypto.randomUUID(),
        expected_review_id: claim.reviews[0]?.id ?? null,
        action: dismissed ? 'reopen' : 'dismiss',
        reason: '',
        confirm: false,
        fact_version_id: '',
        replacement_text: '',
        instructions: '',
    });

    return (
        <form
            className="space-y-3 border-t pt-4 text-sm"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/fact-maintenance/claims/${claim.id}/review`);
            }}
        >
            <label className="block">
                Your decision
                <select
                    className="mt-1 block w-full rounded border bg-background p-2"
                    value={form.data.action}
                    onChange={(event) =>
                        form.setData('action', event.target.value)
                    }
                >
                    {dismissed ? (
                        <option value="reopen">
                            Reopen for another review
                        </option>
                    ) : (
                        <>
                            <option value="dismiss">
                                Dismiss this finding
                            </option>
                            <option value="acknowledge" disabled={!actionable}>
                                Record my review of this assessment
                            </option>
                            <option
                                value={
                                    claim.correction_mode === 'proposal'
                                        ? 'correct'
                                        : 'handoff'
                                }
                                disabled={!actionable}
                            >
                                {claim.correction_mode === 'proposal'
                                    ? 'Prepare a bounded correction proposal'
                                    : 'Prepare an assisted editing handoff'}
                            </option>
                        </>
                    )}
                </select>
            </label>
            <label className="block">
                Reason
                <Textarea
                    className="mt-1"
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    required
                />
            </label>
            {['correct', 'handoff'].includes(form.data.action) && (
                <label className="block">
                    Current confirmed replacement fact
                    <select
                        className="mt-1 block w-full rounded border bg-background p-2"
                        value={form.data.fact_version_id}
                        onChange={(event) =>
                            form.setData('fact_version_id', event.target.value)
                        }
                        required
                    >
                        <option value="">Select evidence</option>
                        {facts
                            .filter((f) => f.usable && f.current)
                            .map((fact) => (
                                <option key={fact.id} value={fact.current!.id}>
                                    {fact.name}: {fact.current!.statement}
                                </option>
                            ))}
                    </select>
                </label>
            )}
            {form.data.action === 'handoff' && (
                <>
                    <p>
                        This source is outside the supported page editor.
                        Prepare exact instructions for the site editor; nothing
                        will be applied automatically.
                    </p>
                    <label className="block">
                        Reviewed replacement wording
                        <Textarea
                            value={form.data.replacement_text}
                            onChange={(event) =>
                                form.setData(
                                    'replacement_text',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </label>
                    <label className="block">
                        Where and how to edit this occurrence
                        <Textarea
                            value={form.data.instructions}
                            onChange={(event) =>
                                form.setData('instructions', event.target.value)
                            }
                            required
                        />
                    </label>
                </>
            )}
            {['acknowledge', 'correct', 'handoff'].includes(
                form.data.action,
            ) && (
                <label className="flex gap-2">
                    <input
                        type="checkbox"
                        checked={form.data.confirm}
                        onChange={(event) =>
                            form.setData('confirm', event.target.checked)
                        }
                        required
                    />
                    I reviewed the exact statement, current evidence and
                    intended scope. This does not authorize publication.
                </label>
            )}
            {Object.values(form.errors).map((error) => (
                <p className="text-destructive" key={error}>
                    {error}
                </p>
            ))}
            <Button disabled={form.processing}>Save review</Button>
        </form>
    );
}
