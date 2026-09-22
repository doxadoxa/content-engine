import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ContextualAssistant } from '@/components/contextual-assistant';
import InputError from '@/components/input-error';
import { PageOutcomeReview } from '@/components/page-outcome-review';
import type { OutcomeContext } from '@/components/page-outcome-review';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';

type Change = {
    kind: string;
    operation: string;
    locator: string;
    before: string;
    after: string;
    reason: string;
    fact_version_ids: string[];
    target_page_id: string | null;
    anchor_text: string | null;
    target_url?: string;
};
type Fact = {
    id: string;
    statement: string;
    source_url: string | null;
    source_note: string;
    confirmed_at: string;
    review_due_at: string;
};
type Revision = {
    id: string;
    number: number;
    changes: Change[];
    missing_facts: string[];
    no_change_reason: string | null;
    evidence_snapshot: Record<string, unknown>;
    measurement_plan: Record<string, unknown>;
    reason: string | null;
    created_at: string;
    snapshot_id: string;
    editable_snapshot_id: string | null;
    compiled_patch: {
        status: string;
        reason?: string;
        destination?: { type: string; object_id: string };
        patches?: {
            field: string;
            operation: string;
            before: string;
            after: string;
        }[];
        title_semantics?: string | null;
        description_semantics?: string | null;
        rendered_changes?: { before: string; after: string }[];
    } | null;
    facts: Fact[];
};
type Check = {
    id: string;
    status: string;
    created_at: string;
    results: {
        passed: boolean;
        fields: { field: string; passed: boolean; detail: string }[];
    };
};
type NativeOperation = {
    id: string;
    kind: string;
    status: string;
    delivery_id: string;
    attempts: number;
    last_error: string | null;
    committed_at: string | null;
    verified_at: string | null;
    retry_at: string | null;
};
type Publication = {
    outcome: OutcomeContext;
    id: string;
    revision_id: string;
    mode: string;
    status: string;
    authorized_at: string;
    applied_at: string | null;
    applied_by_name: string | null;
    application_note: string | null;
    verified_at: string | null;
    checks: Check[];
    operations: NativeOperation[];
    recovered_at: string | null;
};
type Proposal = {
    id: string;
    status: string;
    current_revision_id: string | null;
    approved_revision_id: string | null;
    reason: string | null;
    page: { id: string; title: string; url: string; locale: string };
    opportunity: {
        status: string;
        kind: string;
        issue: string;
        confidence: string;
    };
    revisions: Revision[];
    publications: Publication[];
    reviews: {
        id: string;
        revision_id: string;
        action: string;
        reason: string | null;
        active_seconds: number;
        actor: string | null;
        created_at: string;
    }[];
};
type Props = {
    proposal: Proposal;
    facts: { id: string; name: string; statement: string }[];
    targets: { id: string; title: string; canonical_url: string }[];
    source: { id: string; captured_at: string; revision: string } | null;
};

function useReviewTime() {
    const seconds = useRef(0);
    useEffect(() => {
        let activeAt = Date.now();
        const activity = () => {
            activeAt = Date.now();
        };
        window.addEventListener('pointermove', activity);
        window.addEventListener('keydown', activity);
        const interval = window.setInterval(() => {
            if (
                document.visibilityState === 'visible' &&
                document.hasFocus() &&
                Date.now() - activeAt < 60000
            ) {
                seconds.current = Math.min(3600, seconds.current + 1);
            }
        }, 1000);

        return () => {
            window.clearInterval(interval);
            window.removeEventListener('pointermove', activity);
            window.removeEventListener('keydown', activity);
        };
    }, []);

    return {
        seconds,
        consume: (recorded: number) => {
            seconds.current = Math.max(0, seconds.current - recorded);
        },
    };
}

export default function ProposalShow({
    proposal,
    facts,
    targets,
    source,
}: Props) {
    const { auth, errors, billing } = usePage().props;
    const owner = auth.project?.role === 'owner';
    const [selected, setSelected] = useState('');
    const revision =
        proposal.revisions.find((row) => row.id === selected) ??
        proposal.revisions.find(
            (row) => row.id === proposal.current_revision_id,
        );
    const current = revision?.id === proposal.current_revision_id;
    const approved = current && proposal.approved_revision_id === revision?.id;
    const reviewTime = useReviewTime();
    const [editing, setEditing] = useState(false);
    const action = useForm({});
    useEffect(() => {
        if (
            proposal.status !== 'drafting' &&
            !proposal.publications.some((publication) =>
                publication.operations?.some(
                    (operation) =>
                        ['queued', 'sending'].includes(operation.status) ||
                        Boolean(operation.retry_at),
                ),
            )
        ) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['proposal', 'facts', 'source'] }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [proposal.status, proposal.publications]);
    const publication = proposal.publications.find(
        (row) => row.revision_id === revision?.id,
    );

    return (
        <>
            <Head title={`Review · ${proposal.page.title}`} />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Page improvement"
                    title={proposal.page.title}
                    description={proposal.opportunity.issue}
                    context={`${proposal.page.locale} · ${proposal.opportunity.confidence} confidence`}
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/plan">Back to Plan</Link>
                        </Button>
                    }
                />
                <div className="flex flex-wrap items-center gap-3">
                    <Badge variant="outline">
                        {proposal.status.replaceAll('_', ' ')}
                    </Badge>
                    <a
                        className="text-sm underline"
                        href={proposal.page.url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open live page
                    </a>
                    <Link
                        className="text-sm underline"
                        href={`/pages/${proposal.page.id}`}
                    >
                        Page snapshots
                    </Link>
                    <Link className="text-sm underline" href="/business-facts">
                        Confirm business facts
                    </Link>
                </div>
                {Object.entries(errors).map(([key, value]) => (
                    <InputError key={key} message={value} />
                ))}
                {proposal.reason && (
                    <p className="rounded-xl border bg-muted/30 p-4 text-sm">
                        {proposal.reason}
                    </p>
                )}
                {proposal.status === 'drafting' && (
                    <section className={`${workspacePanelClass} p-6`}>
                        <h2 className="font-semibold">
                            Assessing the page and its evidence
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            The result may be a few supported edits, a request
                            for facts, or a recommendation to leave the page
                            unchanged. This view updates when the review is
                            ready.
                        </p>
                    </section>
                )}
                {proposal.status === 'failed' && (
                    <p className="rounded-xl border p-4 text-sm">
                        The proposal could not be completed. Review the source
                        and facts, then use the revision request below to try
                        again.
                    </p>
                )}
                {revision && (
                    <>
                        <section
                            className={`${workspacePanelClass} p-5 sm:p-6`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-lg font-semibold">
                                    Review the proposed changes
                                </h2>
                                <label className="text-sm">
                                    Revision{' '}
                                    <select
                                        aria-label="Proposal revision"
                                        className="ml-2 rounded-lg border bg-background p-2"
                                        value={revision.id}
                                        onChange={(event) => {
                                            setSelected(event.target.value);
                                            setEditing(false);
                                        }}
                                    >
                                        {proposal.revisions.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.number} ·{' '}
                                                {new Date(
                                                    row.created_at,
                                                ).toLocaleString()}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            {!current && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    Historical revision. Review actions apply to
                                    the current revision.
                                </p>
                            )}
                            {revision.no_change_reason && (
                                <div className="mt-5 rounded-xl border bg-muted/30 p-4">
                                    <h3 className="font-semibold">
                                        No justified change recommended
                                    </h3>
                                    <p className="mt-2 text-sm leading-6">
                                        {revision.no_change_reason}
                                    </p>
                                </div>
                            )}
                            {revision.missing_facts.length > 0 && (
                                <div className="mt-5 rounded-xl border p-4">
                                    <h3 className="font-semibold">
                                        Facts to confirm before acceptance
                                    </h3>
                                    <ul className="mt-2 list-disc space-y-2 pl-5 text-sm">
                                        {revision.missing_facts.map(
                                            (question, i) => (
                                                <li key={i}>{question}</li>
                                            ),
                                        )}
                                    </ul>
                                    <Button
                                        asChild
                                        className="mt-4"
                                        variant="outline"
                                    >
                                        <Link href="/business-facts">
                                            Review and confirm facts
                                        </Link>
                                    </Button>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        After confirmation, request a fresh
                                        revision so the new evidence is pinned
                                        to it.
                                    </p>
                                </div>
                            )}
                            <div className="mt-5 space-y-5">
                                {revision.changes.map((change, i) => (
                                    <article
                                        key={`${revision.id}-${i}`}
                                        className="rounded-xl border p-4"
                                    >
                                        <div className="flex flex-wrap gap-2">
                                            <Badge variant="secondary">
                                                {change.kind.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {change.operation ===
                                                'insert_after'
                                                    ? 'Add one paragraph after this block'
                                                    : 'Change this field or block'}
                                            </span>
                                        </div>
                                        <p className="my-3 text-sm leading-6">
                                            {change.reason}
                                        </p>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div>
                                                <h3 className="text-xs font-semibold text-muted-foreground uppercase">
                                                    Before
                                                </h3>
                                                <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">
                                                    {change.before ||
                                                        'Empty field'}
                                                </p>
                                            </div>
                                            <div>
                                                <h3 className="text-xs font-semibold text-muted-foreground uppercase">
                                                    {change.operation ===
                                                    'insert_after'
                                                        ? 'Paragraph to add'
                                                        : 'After'}
                                                </h3>
                                                <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">
                                                    {change.after}
                                                </p>
                                                {change.kind ===
                                                    'internal_link' && (
                                                    <p className="mt-2 text-sm">
                                                        Link “
                                                        {change.anchor_text}” to{' '}
                                                        <a
                                                            className="underline"
                                                            href={
                                                                change.target_url
                                                            }
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            the selected page
                                                        </a>
                                                        .
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <p className="mt-3 text-xs text-muted-foreground">
                                            {change.fact_version_ids.length}{' '}
                                            confirmed fact{' '}
                                            {change.fact_version_ids.length ===
                                            1
                                                ? 'version supports'
                                                : 'versions support'}{' '}
                                            this edit.
                                        </p>
                                    </article>
                                ))}
                            </div>
                            {revision.compiled_patch && (
                                <div className="mt-5 rounded-xl border p-4">
                                    <h3 className="font-semibold">
                                        Website publishing preview
                                    </h3>
                                    {revision.compiled_patch.status ===
                                    'supported' ? (
                                        <>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                This exact{' '}
                                                {revision.compiled_patch
                                                    .destination?.type ===
                                                'wordpress'
                                                    ? 'WordPress'
                                                    : 'custom website'}{' '}
                                                object (
                                                {
                                                    revision.compiled_patch
                                                        .destination?.object_id
                                                }
                                                ) and editable revision are
                                                pinned. Approval includes the
                                                mapped changes below;
                                                publication still needs a
                                                separate action.
                                            </p>
                                            {revision.compiled_patch.rendered_changes?.map(
                                                (change, index) => (
                                                    <p
                                                        key={index}
                                                        className="mt-3 text-sm"
                                                    >
                                                        Other visible title
                                                        text: “{change.before}”
                                                        → “{change.after}”.
                                                    </p>
                                                ),
                                            )}
                                            {revision.compiled_patch
                                                .title_semantics && (
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    {
                                                        revision.compiled_patch
                                                            .title_semantics
                                                    }
                                                </p>
                                            )}
                                            {revision.compiled_patch
                                                .description_semantics && (
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    {
                                                        revision.compiled_patch
                                                            .description_semantics
                                                    }
                                                </p>
                                            )}
                                            <details className="mt-3">
                                                <summary className="cursor-pointer text-sm font-medium">
                                                    Inspect exact editable field
                                                    changes
                                                </summary>
                                                {revision.compiled_patch.patches?.map(
                                                    (patch, index) => (
                                                        <div
                                                            key={index}
                                                            className="mt-3 space-y-2 text-sm"
                                                        >
                                                            <p className="font-medium">
                                                                {patch.field} ·{' '}
                                                                {
                                                                    patch.operation
                                                                }
                                                            </p>
                                                            <pre className="overflow-auto rounded bg-muted p-2 whitespace-pre-wrap">
                                                                {patch.before ||
                                                                    '(empty)'}
                                                            </pre>
                                                            <pre className="overflow-auto rounded bg-muted p-2 whitespace-pre-wrap">
                                                                {patch.after}
                                                            </pre>
                                                        </div>
                                                    ),
                                                )}
                                            </details>
                                        </>
                                    ) : (
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {revision.compiled_patch.reason}
                                        </p>
                                    )}
                                </div>
                            )}
                            {owner &&
                                current &&
                                (billing?.plan?.version ?? 0) >= 2 && (
                                    <p className="mt-5 text-sm text-muted-foreground">
                                        The first acceptance of this proposal
                                        uses one page improvement. Revisions and
                                        delivery retries are included.
                                        Acceptance does not publish the change.
                                    </p>
                                )}
                            {owner &&
                                current &&
                                !['drafting', 'dismissed'].includes(
                                    proposal.status,
                                ) && (
                                    <div className="mt-6 flex flex-wrap gap-3">
                                        <Button
                                            disabled={
                                                action.processing ||
                                                approved ||
                                                revision.changes.length === 0 ||
                                                revision.missing_facts.length >
                                                    0
                                            }
                                            onClick={() => {
                                                const recorded =
                                                    reviewTime.seconds.current;
                                                action.transform(() => ({
                                                    revision_id: revision.id,
                                                    active_seconds: recorded,
                                                }));
                                                action.post(
                                                    `/proposals/${proposal.id}/accept`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            reviewTime.consume(
                                                                recorded,
                                                            ),
                                                    },
                                                );
                                            }}
                                        >
                                            {approved
                                                ? 'Revision accepted'
                                                : 'Accept this revision'}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                revision.changes.length === 0
                                            }
                                            onClick={() => setEditing(!editing)}
                                        >
                                            {editing
                                                ? 'Close editor'
                                                : 'Revise specific edits'}
                                        </Button>
                                    </div>
                                )}
                            <p className="mt-3 text-xs text-muted-foreground">
                                Acceptance records this revision for publication
                                review. It does not publish or send anything.
                            </p>
                        </section>
                        {editing && owner && current && (
                            <RevisionEditor
                                key={revision.id}
                                proposal={proposal}
                                revision={revision}
                                facts={facts}
                                targets={targets}
                                activeSeconds={() => reviewTime.seconds.current}
                                consumeSeconds={reviewTime.consume}
                                done={() => {
                                    setEditing(false);
                                    setSelected('');
                                }}
                            />
                        )}
                        <section
                            className={`${workspacePanelClass} p-5 sm:p-6`}
                        >
                            <h2 className="text-lg font-semibold">
                                Evidence and follow-up
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                Follow search clicks, impressions and actual
                                recorded purchases after the public changes are
                                verified. Reviews begin around 14 and 28 days;
                                sparse or missing data stays unavailable. These
                                observations do not establish that the edit
                                caused sales.
                            </p>
                            {revision.facts.map(
                                (fact) =>
                                    fact && (
                                        <div
                                            key={fact.id}
                                            className="mt-4 rounded-xl border p-4 text-sm"
                                        >
                                            <p>{fact.statement}</p>
                                            <p className="mt-2 text-muted-foreground">
                                                {fact.source_note}
                                            </p>
                                            {fact.source_url && (
                                                <a
                                                    href={fact.source_url}
                                                    className="mt-2 inline-block underline"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    Supporting source
                                                </a>
                                            )}
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Confirmed{' '}
                                                {new Date(
                                                    fact.confirmed_at,
                                                ).toLocaleDateString()}{' '}
                                                · review due{' '}
                                                {new Date(
                                                    fact.review_due_at,
                                                ).toLocaleDateString()}
                                            </p>
                                        </div>
                                    ),
                            )}
                            <details className="mt-4">
                                <summary className="cursor-pointer text-sm font-medium">
                                    Pinned source and measured evidence
                                </summary>
                                <p className="my-3 text-xs text-muted-foreground">
                                    Snapshot {revision.snapshot_id}
                                    {source && current
                                        ? ` · captured ${new Date(source.captured_at).toLocaleString()}`
                                        : ''}
                                    . Source text is not confirmation of
                                    business claims.
                                </p>
                                <pre className="max-h-96 overflow-auto rounded-xl bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                                    {JSON.stringify(
                                        revision.evidence_snapshot,
                                        null,
                                        2,
                                    )}
                                </pre>
                            </details>
                        </section>
                        {owner &&
                            approved &&
                            (!publication ||
                                (publication.status === 'review_required' &&
                                    publication.mode === 'assisted')) && (
                                <section
                                    className={`${workspacePanelClass} p-5 sm:p-6`}
                                >
                                    <h2 className="text-lg font-semibold">
                                        Choose how to publish
                                    </h2>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        Choose the supported website connection
                                        or an assisted handoff. Both use this
                                        exact approved revision and check that
                                        its source still matches. An assisted
                                        handoff prepares instructions for your
                                        website editor; it does not itself
                                        change the page.
                                    </p>
                                    {revision.compiled_patch?.status ===
                                        'supported' &&
                                        !publication && (
                                            <Button
                                                className="mt-4 mr-3"
                                                disabled={action.processing}
                                                onClick={() => {
                                                    action.transform(() => ({
                                                        revision_id:
                                                            revision.id,
                                                    }));
                                                    action.post(
                                                        `/proposals/${proposal.id}/publish-native`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }}
                                            >
                                                Publish approved website changes
                                            </Button>
                                        )}
                                    <Button
                                        className="mt-4"
                                        disabled={action.processing}
                                        onClick={() => {
                                            action.transform(() => ({
                                                revision_id: revision.id,
                                            }));
                                            action.post(
                                                `/proposals/${proposal.id}/publish`,
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        Authorize assisted publication
                                    </Button>
                                </section>
                            )}
                        {publication && publication.mode !== 'assisted' && (
                            <NativePublicationPanel
                                publication={publication}
                                owner={Boolean(owner)}
                            />
                        )}
                        {publication && publication.mode === 'assisted' && (
                            <PublicationPanel
                                key={publication.id}
                                publication={publication}
                                owner={owner}
                                currentApproved={
                                    Boolean(approved) &&
                                    publication.status !== 'review_required'
                                }
                            />
                        )}
                    </>
                )}
                {publication?.outcome?.verified && (
                    <section className={`${workspacePanelClass} p-5 sm:p-6`}>
                        <PageOutcomeReview outcome={publication.outcome} />
                    </section>
                )}
                {owner &&
                    (proposal.status !== 'dismissed' ||
                        proposal.opportunity.status === 'open') && (
                        <ReviewRequest
                            key={proposal.current_revision_id ?? 'initial'}
                            proposal={proposal}
                            activeSeconds={() => reviewTime.seconds.current}
                            consumeSeconds={reviewTime.consume}
                        />
                    )}
                {proposal.status === 'dismissed' && (
                    <p className="text-sm text-muted-foreground">
                        This proposal is dismissed. Reconsider the opportunity
                        in Plan before requesting another revision.
                    </p>
                )}
                {proposal.reviews.length > 0 && (
                    <details className={`${workspacePanelClass} p-5`}>
                        <summary className="cursor-pointer font-semibold">
                            Review history and time
                        </summary>
                        <div className="mt-4 space-y-3">
                            {proposal.reviews.map((review) => (
                                <p key={review.id} className="text-sm">
                                    {review.actor ?? 'Former operator'} ·{' '}
                                    {review.action} · {review.active_seconds}s
                                    active review ·{' '}
                                    {new Date(
                                        review.created_at,
                                    ).toLocaleString()}
                                    {review.reason && ` — ${review.reason}`}
                                </p>
                            ))}
                        </div>
                    </details>
                )}
                <ContextualAssistant
                    label="Discuss this proposal"
                    context={`Reviewing proposal ${proposal.id} for existing page ${proposal.page.url}, revision ${proposal.current_revision_id ?? 'pending'}. Explain evidence or missing facts; approval and publication require their explicit separate actions.`}
                />
            </WorkspacePage>
        </>
    );
}

function RevisionEditor({
    proposal,
    revision,
    facts,
    targets,
    activeSeconds,
    consumeSeconds,
    done,
}: {
    proposal: Proposal;
    revision: Revision;
    facts: Props['facts'];
    targets: Props['targets'];
    activeSeconds: () => number;
    consumeSeconds: (recorded: number) => void;
    done: () => void;
}) {
    const form = useForm({
        revision_id: revision.id,
        reason: '',
        changes: revision.changes.map((change) => {
            const next = { ...change };
            delete next.target_url;

            return next;
        }),
        missing_facts: revision.missing_facts,
        no_change_reason: revision.no_change_reason,
        active_seconds: 0,
    });
    const changeAt = (index: number, update: Partial<Change>) =>
        form.setData(
            'changes',
            form.data.changes.map((change, i) =>
                i === index ? { ...change, ...update } : change,
            ),
        );

    return (
        <form
            className={`${workspacePanelClass} space-y-5 p-5 sm:p-6`}
            onSubmit={(event) => {
                event.preventDefault();
                const recorded = activeSeconds();
                form.transform((data) => ({
                    ...data,
                    active_seconds: recorded,
                }));
                form.post(`/proposals/${proposal.id}/revisions`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        consumeSeconds(recorded);
                        done();
                    },
                });
            }}
        >
            <h2 className="text-lg font-semibold">Revise the exact changes</h2>
            <p className="text-sm text-muted-foreground">
                The source and original values stay pinned. Changing this
                proposal clears approval. If the page source changed, request a
                fresh revision below.
            </p>
            {form.data.changes.map((change, i) => (
                <div key={i} className="space-y-3 rounded-xl border p-4">
                    <Label htmlFor={`after-${i}`}>
                        {change.kind.replaceAll('_', ' ')} · {change.operation}
                    </Label>
                    <Textarea
                        id={`after-${i}`}
                        value={change.after}
                        disabled={change.kind === 'internal_link'}
                        onChange={(e) => changeAt(i, { after: e.target.value })}
                        rows={5}
                    />
                    <Label htmlFor={`reason-${i}`}>Why this change helps</Label>
                    <Textarea
                        id={`reason-${i}`}
                        value={change.reason}
                        onChange={(e) =>
                            changeAt(i, { reason: e.target.value })
                        }
                        rows={2}
                    />
                    {change.kind === 'internal_link' && (
                        <>
                            <Label htmlFor={`anchor-${i}`}>
                                Exact existing phrase to link
                            </Label>
                            <Input
                                id={`anchor-${i}`}
                                value={change.anchor_text ?? ''}
                                onChange={(e) =>
                                    changeAt(i, { anchor_text: e.target.value })
                                }
                            />
                            <Label htmlFor={`target-${i}`}>Target page</Label>
                            <select
                                id={`target-${i}`}
                                className="w-full rounded-lg border bg-background p-2 text-sm"
                                value={change.target_page_id ?? ''}
                                onChange={(e) =>
                                    changeAt(i, {
                                        target_page_id: e.target.value,
                                    })
                                }
                            >
                                {targets.map((target) => (
                                    <option key={target.id} value={target.id}>
                                        {target.title}
                                    </option>
                                ))}
                            </select>
                        </>
                    )}
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-sm font-medium">
                            Confirmed evidence supporting this edit
                        </legend>
                        {facts.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Confirm business facts before accepting text
                                changes.
                            </p>
                        )}
                        {facts.map((fact) => (
                            <label
                                key={fact.id}
                                className="flex items-start gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={change.fact_version_ids.includes(
                                        fact.id,
                                    )}
                                    onChange={(event) =>
                                        changeAt(i, {
                                            fact_version_ids: event.target
                                                .checked
                                                ? [
                                                      ...change.fact_version_ids,
                                                      fact.id,
                                                  ]
                                                : change.fact_version_ids.filter(
                                                      (id) => id !== fact.id,
                                                  ),
                                        })
                                    }
                                    className="mt-1"
                                />
                                <span>
                                    {fact.name} — {fact.statement}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <Button
                        variant="ghost"
                        type="button"
                        onClick={() =>
                            form.setData(
                                'changes',
                                form.data.changes.filter(
                                    (_, index) => index !== i,
                                ),
                            )
                        }
                    >
                        Remove this edit
                    </Button>
                </div>
            ))}
            <Label htmlFor="revision-reason">What did you correct?</Label>
            <Textarea
                id="revision-reason"
                required
                value={form.data.reason}
                onChange={(e) => form.setData('reason', e.target.value)}
            />
            {form.data.changes.length === 0 && (
                <div className="space-y-2">
                    <Label htmlFor="no-change-reason">
                        Why is no page change justified?
                    </Label>
                    <Textarea
                        id="no-change-reason"
                        value={form.data.no_change_reason ?? ''}
                        maxLength={2000}
                        onChange={(event) =>
                            form.setData('no_change_reason', event.target.value)
                        }
                    />
                    <InputError message={form.errors.no_change_reason} />
                </div>
            )}
            <Label htmlFor="missing-facts">
                Remaining fact questions, one per line
            </Label>
            <Textarea
                id="missing-facts"
                value={form.data.missing_facts.join('\n')}
                onChange={(e) =>
                    form.setData(
                        'missing_facts',
                        e.target.value.split('\n').filter(Boolean),
                    )
                }
            />
            {Object.entries(form.errors).map(([key, value]) => (
                <InputError key={key} message={value} />
            ))}
            <Button disabled={form.processing}>Save as a new revision</Button>
        </form>
    );
}

function ReviewRequest({
    proposal,
    activeSeconds,
    consumeSeconds,
}: {
    proposal: Proposal;
    activeSeconds: () => number;
    consumeSeconds: (recorded: number) => void;
}) {
    const form = useForm({
        revision_id: proposal.current_revision_id,
        reason: '',
        active_seconds: 0,
    });

    return (
        <section className={`${workspacePanelClass} p-5 sm:p-6`}>
            <h2 className="text-lg font-semibold">
                Request a revision or leave this page unchanged
            </h2>
            <form
                className="mt-4 space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/proposals/${proposal.id}/regenerate`, {
                        preserveScroll: true,
                    });
                }}
            >
                <Label htmlFor="review-request">
                    Explain the correction, new confirmed fact, or reason to
                    stop
                </Label>
                <Textarea
                    id="review-request"
                    required
                    maxLength={2000}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    rows={3}
                />
                <InputError message={form.errors.reason} />
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="outline"
                        disabled={
                            form.processing ||
                            proposal.status === 'drafting' ||
                            (proposal.status === 'dismissed' &&
                                proposal.opportunity.status !== 'open')
                        }
                    >
                        Request a fresh proposal
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={form.processing || !form.data.reason.trim()}
                        onClick={() => {
                            const recorded = activeSeconds();
                            form.transform((data) => ({
                                ...data,
                                active_seconds: recorded,
                            }));
                            form.post(`/proposals/${proposal.id}/dismiss`, {
                                preserveScroll: true,
                                onSuccess: () => consumeSeconds(recorded),
                            });
                        }}
                    >
                        Dismiss with this reason
                    </Button>
                </div>
            </form>
        </section>
    );
}

function PublicationPanel({
    publication,
    owner,
    currentApproved,
}: {
    publication: Publication;
    owner: boolean;
    currentApproved: boolean;
}) {
    const form = useForm({
        revision_id: publication.revision_id,
        applied_by_name: '',
        applied_at: '',
        application_note: '',
        confirm_applied: false,
    });
    const check = useForm({});

    return (
        <section className={`${workspacePanelClass} p-5 sm:p-6`}>
            <div className="flex flex-wrap justify-between gap-3">
                <h2 className="text-lg font-semibold">
                    Publication and public verification
                </h2>
                <Badge variant="outline">
                    {publication.status.replaceAll('_', ' ')}
                </Badge>
            </div>
            {publication.verified_at ? (
                <p className="mt-3 text-sm">
                    Verified live{' '}
                    {new Date(publication.verified_at).toLocaleString()}.
                    Follow-up measurement starts from this verified date.
                </p>
            ) : (
                <p className="mt-3 text-sm text-muted-foreground">
                    A handoff download or an operator report does not establish
                    that the reviewed changes are live.
                </p>
            )}
            {owner && currentApproved && (
                <Button asChild className="mt-4" variant="outline">
                    <a href={`/publications/${publication.id}/handoff`}>
                        Download exact handoff
                    </a>
                </Button>
            )}
            {publication.applied_at && (
                <p className="mt-4 text-sm">
                    Applied by {publication.applied_by_name} at{' '}
                    {new Date(publication.applied_at).toLocaleString()}.{' '}
                    {publication.application_note}
                </p>
            )}
            {owner && currentApproved && !publication.applied_at && (
                <form
                    className="mt-5 space-y-3 border-t pt-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            applied_at: new Date(data.applied_at).toISOString(),
                        }));
                        form.post(`/publications/${publication.id}/applied`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <h3 className="font-semibold">
                        Record the actual application
                    </h3>
                    <Label htmlFor="applied-by">
                        Who applied these changes?
                    </Label>
                    <Input
                        id="applied-by"
                        required
                        value={form.data.applied_by_name}
                        onChange={(e) =>
                            form.setData('applied_by_name', e.target.value)
                        }
                    />
                    <Label htmlFor="applied-at">
                        When were they applied? (your local time)
                    </Label>
                    <Input
                        id="applied-at"
                        required
                        type="datetime-local"
                        step={1}
                        value={form.data.applied_at}
                        onChange={(e) =>
                            form.setData('applied_at', e.target.value)
                        }
                    />
                    <Label htmlFor="application-note">
                        Application notes or editor reference
                    </Label>
                    <Textarea
                        id="application-note"
                        required
                        value={form.data.application_note}
                        onChange={(e) =>
                            form.setData('application_note', e.target.value)
                        }
                    />
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.confirm_applied}
                            onChange={(e) =>
                                form.setData(
                                    'confirm_applied',
                                    e.target.checked,
                                )
                            }
                            className="mt-1"
                        />
                        These exact approved changes were applied to the
                        website.
                    </label>
                    {Object.entries(form.errors).map(([key, value]) => (
                        <InputError key={key} message={value} />
                    ))}
                    <Button disabled={form.processing}>
                        Record application
                    </Button>
                </form>
            )}
            {owner &&
                currentApproved &&
                publication.applied_at &&
                !publication.verified_at && (
                    <Button
                        className="mt-4"
                        disabled={check.processing}
                        onClick={() =>
                            check.post(
                                `/publications/${publication.id}/verify`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        {check.processing
                            ? 'Checking the live page…'
                            : 'Verify the public changes'}
                    </Button>
                )}
            {publication.checks.map((result) => (
                <div key={result.id} className="mt-4 rounded-xl border p-4">
                    <p className="text-sm font-semibold">
                        {result.status} ·{' '}
                        {new Date(result.created_at).toLocaleString()}
                    </p>
                    <ul className="mt-3 space-y-2 text-sm">
                        {result.results.fields.map((field, index) => (
                            <li key={index}>
                                <span className="font-medium">
                                    {field.passed ? '✓' : 'Needs attention'}{' '}
                                    {field.field}:
                                </span>{' '}
                                {field.detail}
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}

ProposalShow.layout = {
    breadcrumbs: [
        { title: 'Plan', href: '/plan' },
        { title: 'Review page improvement', href: '#' },
    ],
};

function NativePublicationPanel({
    publication,
    owner,
}: {
    publication: Publication;
    owner: boolean;
}) {
    const action = useForm({});
    const recover = useForm({ confirm_recovery: false });
    const operations = publication.operations ?? [];
    const original = operations.find(
        (operation) => operation.kind === 'publish',
    );
    const recovery = operations.find(
        (operation) => operation.kind === 'recovery',
    );
    const pending = operations.some((operation) =>
        ['queued', 'sending', 'outcome_unknown', 'blocked_unresolved'].includes(
            operation.status,
        ),
    );

    return (
        <section className={`${workspacePanelClass} space-y-4 p-5 sm:p-6`}>
            <div className="flex justify-between gap-3">
                <h2 className="text-lg font-semibold">Website publication</h2>
                <Badge variant="outline">
                    {publication.status.replaceAll('_', ' ')}
                </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
                A receiver receipt records application. Only a separate public
                check confirms the approved result is visible. An uncertain
                response keeps its original delivery identity.
            </p>
            {operations.map((operation) => (
                <div
                    key={operation.id}
                    className="space-y-2 rounded-xl border p-4"
                >
                    <h3 className="font-medium">
                        {operation.kind === 'recovery'
                            ? 'Recovery'
                            : 'Publication'}{' '}
                        · {operation.status.replaceAll('_', ' ')}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Delivery {operation.delivery_id} · {operation.attempts}{' '}
                        recorded transport checks
                    </p>
                    {operation.last_error && (
                        <p className="text-sm">{operation.last_error}</p>
                    )}
                    {operation.committed_at && (
                        <p className="text-sm">
                            Receiver confirmed{' '}
                            {new Date(operation.committed_at).toLocaleString()}
                            {operation.verified_at
                                ? ` · public result verified ${new Date(operation.verified_at).toLocaleString()}`
                                : ' · public verification pending'}
                        </p>
                    )}
                    {owner &&
                        !operation.committed_at &&
                        !['conflict', 'unsupported', 'cancelled'].includes(
                            operation.status,
                        ) && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    disabled={action.processing}
                                    onClick={() => {
                                        action.transform(() => ({}));
                                        action.post(
                                            `/page-operations/${operation.id}/reconcile`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Check original outcome
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={action.processing}
                                    onClick={() => {
                                        action.transform(() => ({
                                            confirm_retry: true,
                                        }));
                                        action.post(
                                            `/page-operations/${operation.id}/retry`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Retry the same approved operation
                                </Button>
                            </div>
                        )}
                </div>
            ))}
            {owner && original?.committed_at && !pending && (
                <Button
                    variant="outline"
                    disabled={action.processing}
                    onClick={() => {
                        action.transform(() => ({}));
                        action.post(`/publications/${publication.id}/verify`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    Verify {recovery?.committed_at ? 'recovered' : 'published'}{' '}
                    public page
                </Button>
            )}
            {owner && original?.committed_at && !recovery && !pending && (
                <form
                    className="space-y-3 border-t pt-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        recover.post(
                            `/publications/${publication.id}/recovery`,
                            { preserveScroll: true },
                        );
                    }}
                >
                    <h3 className="font-semibold">
                        Recover the previous fields
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        Restore only the fields changed by this operation. The
                        CMS must still match its recorded result; subsequent
                        external edits prevent recovery. The recovered page
                        needs another public check.
                    </p>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            required
                            checked={recover.data.confirm_recovery}
                            onChange={(event) =>
                                recover.setData(
                                    'confirm_recovery',
                                    event.target.checked,
                                )
                            }
                        />{' '}
                        I authorize restoring this publication’s previous
                        fields.
                    </label>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={
                            recover.processing || !recover.data.confirm_recovery
                        }
                    >
                        Recover previous fields
                    </Button>
                </form>
            )}
            {publication.checks.map((check) => (
                <details key={check.id}>
                    <summary className="cursor-pointer text-sm">
                        Public check ·{' '}
                        {new Date(check.created_at).toLocaleString()} ·{' '}
                        {check.status}
                    </summary>
                    <ul className="mt-2 space-y-2">
                        {check.results.fields.map((field, index) => (
                            <li key={index} className="text-sm">
                                <span className="font-medium">
                                    {field.passed ? 'Passed' : 'Needs review'} ·{' '}
                                    {field.field}
                                </span>
                                <p className="text-muted-foreground">
                                    {field.detail}
                                </p>
                            </li>
                        ))}
                    </ul>
                </details>
            ))}
        </section>
    );
}
