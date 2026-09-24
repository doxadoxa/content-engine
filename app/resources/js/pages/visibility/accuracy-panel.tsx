import { Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { workspacePanelClass } from '@/components/workspace-page';

type Fact = {
    id: string;
    statement: string;
    source_url: string | null;
    source_note: string;
    confirmed_at: string;
    review_due_at: string;
};
type Action = {
    id: string;
    kind: string;
    draft: string;
    opportunity_id: string | null;
    updates: { id: string; status: string; note: string; created_at: string }[];
    rechecks: { id: string; sampling_run_id: string }[];
};
type Finding = {
    references: { id: string; url: string; title: string }[];
    id: string;
    relation: string;
    fact_version_id: string | null;
    evidence: {
        exactQuote: string;
        reason: string;
        sectionKey: string;
        startCodepoint: number;
        endCodepoint: number;
        referenceIds: string[];
    };
    review: { id: string; decision: string; reason: string } | null;
    review_history: {
        id: string;
        decision: string;
        reason: string;
        created_at: string;
    }[];
    can_edit_page: boolean;
    can_correct: boolean;
    actions: Action[];
};
type Assessment = {
    id: string;
    scope: string;
    status: string;
    created_at: string;
    facts_current: boolean;
    source_current: boolean;
    fact_versions: Fact[];
    source_metadata: { source_url?: string; captured_at?: string };
    result: {
        status: string;
        coverage: {
            total_characters: number;
            assessed_characters: number;
            attempted_calls: number;
        };
        limitations: string[];
        checker: {
            policy_version: string;
            calls: {
                provider: string;
                model: string;
                input_tokens: number;
                output_tokens: number;
                usage_status?: string;
            }[];
        };
    } | null;
    findings: Finding[];
};
export type AccuracyData = {
    current_fact_count: number;
    assessments: Assessment[];
    tracked_pages: { id: string; title: string; canonical_url: string }[];
};

export default function AccuracyPanel({
    answerId,
    owner,
    accuracy,
}: {
    answerId: string;
    owner: boolean;
    accuracy: AccuracyData;
}) {
    const form = useForm({ request_key: crypto.randomUUID() });
    usePoll(15000, { only: ['accuracy'] });

    return (
        <section className={`${workspacePanelClass} p-5`}>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="font-semibold">Check the business facts</h2>
                    <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Compare the complete stored answer with current
                        owner-confirmed facts. Findings are suggestions to
                        review. Citations do not prove where an error
                        originated, and a website edit cannot guarantee a
                        different AI answer.
                    </p>
                </div>
                {owner && (
                    <Button
                        disabled={
                            form.processing || accuracy.current_fact_count === 0
                        }
                        onClick={() =>
                            form.post(
                                `/visibility/answers/${answerId}/assess`,
                                {
                                    onSuccess: () =>
                                        form.setData(
                                            'request_key',
                                            crypto.randomUUID(),
                                        ),
                                },
                            )
                        }
                    >
                        Check against current facts
                    </Button>
                )}
            </div>
            <p className="mt-3 text-xs text-muted-foreground">
                {accuracy.current_fact_count} current confirmed facts available.
                Checks use metered model calls and show their text coverage.{' '}
                <Link className="underline" href="/business-facts">
                    Review business facts
                </Link>
            </p>
            <InputError message={form.errors.request_key} />
            {accuracy.assessments.length === 0 && (
                <p className="mt-5 text-sm">
                    No factual assessment has been recorded for this answer.
                </p>
            )}
            <div className="mt-5 space-y-5">
                {accuracy.assessments.map((assessment) => (
                    <article
                        key={assessment.id}
                        className="rounded-xl border p-4"
                    >
                        <h3 className="font-medium">
                            {assessment.scope === 'answer'
                                ? 'Recorded AI answer'
                                : 'Selected website page'}{' '}
                            · {assessment.status.replaceAll('_', ' ')}
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {new Date(assessment.created_at).toLocaleString()}
                        </p>
                        {assessment.source_metadata.source_url && (
                            <a
                                href={assessment.source_metadata.source_url}
                                target="_blank"
                                rel="noreferrer"
                                className="mt-2 block text-sm break-all underline"
                            >
                                {assessment.source_metadata.source_url}
                            </a>
                        )}
                        {!assessment.facts_current && (
                            <p className="mt-3 text-sm text-amber-700">
                                These fact versions are unavailable or no longer
                                current. Review the current facts and request a
                                fresh check before acting.
                            </p>
                        )}
                        {!assessment.source_current && (
                            <p className="mt-3 text-sm text-amber-700">
                                This website page has changed or is no longer
                                tracked. Capture and assess it again before
                                proposing a correction.
                            </p>
                        )}
                        {assessment.result && (
                            <>
                                <p className="mt-3 text-sm">
                                    Stored text assessed:{' '}
                                    {assessment.result.coverage.assessed_characters.toLocaleString()}{' '}
                                    /{' '}
                                    {assessment.result.coverage.total_characters.toLocaleString()}{' '}
                                    characters ·{' '}
                                    {assessment.result.coverage.attempted_calls}{' '}
                                    checker calls.
                                </p>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                    {assessment.result.limitations.map(
                                        (limitation, i) => (
                                            <li key={i}>{limitation}</li>
                                        ),
                                    )}
                                </ul>
                                <details className="mt-3 text-xs">
                                    <summary className="cursor-pointer">
                                        Checker and recorded usage
                                    </summary>
                                    <p className="mt-2">
                                        Policy:{' '}
                                        {
                                            assessment.result.checker
                                                .policy_version
                                        }
                                    </p>
                                    {assessment.result.checker.calls.map(
                                        (call, i) => (
                                            <p key={i}>
                                                {call.provider} · {call.model} ·{' '}
                                                {call.usage_status ===
                                                'unavailable'
                                                    ? 'Token usage unavailable; cost is unknown.'
                                                    : `${call.input_tokens} input / ${call.output_tokens} output tokens`}
                                            </p>
                                        ),
                                    )}
                                </details>
                            </>
                        )}
                        {['complete', 'partial'].includes(assessment.status) &&
                            assessment.findings.length === 0 && (
                                <p className="mt-4 text-sm">
                                    No discrepancy was proposed in the assessed
                                    text. This does not establish that every
                                    claim is correct.{' '}
                                    {assessment.scope === 'owned_page' &&
                                        'No website correction is created; the original answer finding can still support an external handoff.'}
                                </p>
                            )}
                        <div className="mt-4 space-y-4">
                            {assessment.findings.map((finding) => (
                                <FindingCard
                                    key={`${finding.id}:${finding.review?.id ?? 'new'}`}
                                    finding={finding}
                                    assessment={assessment}
                                    owner={owner}
                                    pages={accuracy.tracked_pages}
                                />
                            ))}
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function FindingCard({
    finding,
    assessment,
    owner,
    pages,
}: {
    finding: Finding;
    assessment: Assessment;
    owner: boolean;
    pages: AccuracyData['tracked_pages'];
}) {
    const review = useForm({
        decision: 'confirmed',
        reason: '',
        expected_review_id: finding.review?.id ?? null,
        reopen: false,
    });
    const page = useForm({
        request_key: crypto.randomUUID(),
        site_page_id: '',
    });
    const correction = useForm({});
    const fact = assessment.fact_versions.find(
        (fact) => fact.id === finding.fact_version_id,
    );

    return (
        <div className="rounded-lg border bg-muted/20 p-4">
            <p className="text-xs font-medium tracking-wide uppercase">
                Proposed:{' '}
                {finding.relation === 'contradicted'
                    ? 'Conflicting claim'
                    : finding.relation === 'supported'
                      ? 'Supported by a confirmed fact'
                      : 'Insufficient business evidence'}
            </p>
            <blockquote className="mt-3 border-l-2 pl-4 text-sm leading-7 whitespace-pre-wrap">
                {finding.evidence.exactQuote}
            </blockquote>
            <p className="mt-2 text-xs text-muted-foreground">
                Exact quote from {finding.evidence.sectionKey}, characters{' '}
                {finding.evidence.startCodepoint}–
                {finding.evidence.endCodepoint}.
            </p>
            <p className="mt-3 text-sm">{finding.evidence.reason}</p>
            {finding.references.map((reference) => (
                <a
                    className="mt-2 block text-xs break-all underline"
                    key={reference.id}
                    href={reference.url}
                    target="_blank"
                    rel="noreferrer"
                >
                    Cited evidence: {reference.title || reference.url}
                </a>
            ))}
            {fact && (
                <div className="mt-3 rounded-md border bg-background p-3 text-sm">
                    <p className="font-medium">Confirmed business fact</p>
                    <p className="mt-1">{fact.statement}</p>
                    {fact.source_url && (
                        <a
                            className="mt-2 block break-all underline"
                            href={fact.source_url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {fact.source_url}
                        </a>
                    )}
                    <p className="mt-2 text-xs text-muted-foreground">
                        {fact.source_note} · Confirmed{' '}
                        {new Date(fact.confirmed_at).toLocaleDateString()} ·
                        Review due{' '}
                        {new Date(fact.review_due_at).toLocaleDateString()}
                    </p>
                </div>
            )}
            {finding.review && (
                <p className="mt-3 text-sm">
                    Owner review: {finding.review.decision}.{' '}
                    {finding.review.reason}
                </p>
            )}
            {finding.review_history.length > 0 && (
                <details className="mt-3 text-xs">
                    <summary>Owner review history</summary>
                    {finding.review_history.map((item) => (
                        <p key={item.id} className="mt-2">
                            {new Date(item.created_at).toLocaleString()} ·{' '}
                            {item.decision}: {item.reason}
                        </p>
                    ))}
                </details>
            )}
            {owner && (
                <form
                    className="mt-3 space-y-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        review.post(
                            `/visibility/findings/${finding.id}/review`,
                        );
                    }}
                >
                    <label
                        className="block text-xs font-medium"
                        htmlFor={`review-${finding.id}`}
                    >
                        Review reason
                    </label>
                    <Textarea
                        id={`review-${finding.id}`}
                        required
                        maxLength={2000}
                        value={review.data.reason}
                        onChange={(event) =>
                            review.setData('reason', event.target.value)
                        }
                    />
                    <select
                        aria-label="Review decision"
                        className="rounded-md border bg-background p-2 text-sm"
                        value={review.data.decision}
                        onChange={(event) =>
                            review.setData('decision', event.target.value)
                        }
                    >
                        <option value="confirmed">Confirm assessment</option>
                        <option value="dismissed">Dismiss assessment</option>
                    </select>
                    {finding.review?.decision === 'dismissed' &&
                        review.data.decision === 'confirmed' && (
                            <label className="flex gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={review.data.reopen}
                                    onChange={(event) =>
                                        review.setData(
                                            'reopen',
                                            event.target.checked,
                                        )
                                    }
                                />
                                I reviewed the dismissal and want to reopen this
                                finding.
                            </label>
                        )}
                    <Button
                        className="ml-2"
                        variant="outline"
                        disabled={
                            review.processing ||
                            (review.data.decision === 'confirmed' &&
                                !assessment.facts_current)
                        }
                    >
                        Save review
                    </Button>
                    <InputError message={review.errors.reason} />
                </form>
            )}
            {owner && finding.can_correct && assessment.scope === 'answer' && (
                <div className="mt-4 space-y-3 border-t pt-4">
                    <p className="text-sm">
                        Inspect a tracked page before changing it. A separate
                        confirmed page discrepancy is required to add a
                        correction to your plan.
                    </p>
                    <select
                        aria-label="Page to inspect"
                        className="w-full rounded-md border bg-background p-2 text-sm"
                        value={page.data.site_page_id}
                        onChange={(event) =>
                            page.setData('site_page_id', event.target.value)
                        }
                    >
                        <option value="">Choose a tracked page</option>
                        {pages.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.title || item.canonical_url}
                            </option>
                        ))}
                    </select>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={
                                page.processing || !page.data.site_page_id
                            }
                            onClick={() =>
                                page.post(
                                    `/visibility/findings/${finding.id}/inspect-page`,
                                    {
                                        onSuccess: () =>
                                            page.setData(
                                                'request_key',
                                                crypto.randomUUID(),
                                            ),
                                    },
                                )
                            }
                        >
                            Capture and check selected page
                        </Button>
                        <Button
                            variant="outline"
                            disabled={correction.processing}
                            onClick={() =>
                                correction.post(
                                    `/visibility/findings/${finding.id}/handoff`,
                                )
                            }
                        >
                            Prepare external handoff
                        </Button>
                    </div>
                    {Object.values(page.errors).map((message, i) => (
                        <InputError key={i} message={message} />
                    ))}
                </div>
            )}
            {owner &&
                finding.can_correct &&
                assessment.scope === 'owned_page' && (
                    <Button
                        className="mt-4"
                        disabled={correction.processing}
                        onClick={() =>
                            correction.post(
                                `/visibility/findings/${finding.id}/owned-page`,
                            )
                        }
                    >
                        {finding.can_edit_page
                            ? 'Add page correction to plan'
                            : 'Prepare assisted page handoff'}
                    </Button>
                )}
            {finding.actions.map((action) => (
                <ActionCard
                    key={action.id}
                    action={action}
                    owner={owner}
                    current={finding.can_correct}
                />
            ))}
        </div>
    );
}

function ActionCard({
    action,
    owner,
    current,
}: {
    action: Action;
    owner: boolean;
    current: boolean;
}) {
    const [copied, setCopied] = useState(false);
    const update = useForm({ status: 'owner_submitted', note: '' });
    const recheck = useForm({ request_key: crypto.randomUUID() });
    const { billing } = usePage().props;
    const limited = Boolean(billing?.plan);

    return (
        <div className="mt-4 rounded-lg border bg-background p-4">
            <h4 className="text-sm font-semibold">
                {action.kind === 'owned_page'
                    ? 'Reviewed page correction'
                    : action.kind === 'owned_page_assisted'
                      ? 'Assisted owned-page handoff'
                      : 'External handoff draft'}
            </h4>
            <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                {action.draft}
            </p>
            {action.kind === 'owned_page' && (
                <Link href="/plan" className="mt-3 block text-sm underline">
                    Open the page correction plan
                </Link>
            )}
            {['external_handoff', 'owned_page_assisted'].includes(
                action.kind,
            ) && (
                <>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Nothing is sent from Avyo. Review this draft and submit
                        it yourself through the relevant provider or source
                        owner’s correction process.
                    </p>
                    {owner && (
                        <>
                            <Button
                                className="mt-3"
                                variant="outline"
                                disabled={!current}
                                onClick={async () => {
                                    await navigator.clipboard.writeText(
                                        action.draft,
                                    );
                                    setCopied(true);
                                }}
                            >
                                {copied ? 'Copied' : 'Copy draft'}
                            </Button>
                            <form
                                className="mt-4 space-y-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    update.post(
                                        `/visibility/corrections/${action.id}/record-handoff`,
                                    );
                                }}
                            >
                                <label
                                    className="block text-xs font-medium"
                                    htmlFor={`handoff-${action.id}`}
                                >
                                    Record your action and destination
                                </label>
                                <Textarea
                                    id={`handoff-${action.id}`}
                                    required
                                    value={update.data.note}
                                    onChange={(event) =>
                                        update.setData(
                                            'note',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={2000}
                                />
                                <select
                                    aria-label="Handoff status"
                                    className="rounded-md border bg-background p-2 text-sm"
                                    value={update.data.status}
                                    onChange={(event) =>
                                        update.setData(
                                            'status',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="owner_submitted">
                                        I submitted the handoff
                                    </option>
                                    <option value="owner_cancelled">
                                        I cancelled the handoff
                                    </option>
                                </select>
                                <Button
                                    className="ml-2"
                                    variant="outline"
                                    disabled={
                                        update.processing ||
                                        (!current &&
                                            update.data.status ===
                                                'owner_submitted')
                                    }
                                >
                                    Record my action
                                </Button>
                                <InputError message={update.errors.note} />
                            </form>
                        </>
                    )}
                    {action.updates.map((item) => (
                        <p key={item.id} className="mt-2 text-xs">
                            {new Date(item.created_at).toLocaleString()} ·{' '}
                            {item.status.replaceAll('_', ' ')} · {item.note}
                        </p>
                    ))}
                </>
            )}
            {owner && (
                <Button
                    className="mt-4"
                    variant="outline"
                    disabled={
                        recheck.processing ||
                        (limited &&
                            (!billing?.may_generate ||
                                billing.usage.ai_answers?.remaining === 0))
                    }
                    onClick={() =>
                        recheck.post(
                            `/visibility/corrections/${action.id}/recheck`,
                        )
                    }
                >
                    Recheck the same question and facts{limited && ' · 1 check'}
                </Button>
            )}
            <p className="mt-2 text-xs text-muted-foreground">
                A later answer is another observation. It does not establish
                that this action caused the change or that the issue is
                permanently resolved.
            </p>
            {action.rechecks.map((item) => (
                <Link
                    key={item.id}
                    className="mt-2 block text-sm underline"
                    href={`/visibility/runs/${item.sampling_run_id}`}
                >
                    Inspect recorded recheck
                </Link>
            ))}
        </div>
    );
}
