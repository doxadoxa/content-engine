import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';

type Search = { clicks: number | null; impressions: number | null };
type Opportunity = {
    id: string;
    kind: string;
    status: string;
    page: { id: string; title: string; url: string; locale: string };
    diagnosed_issue: string;
    suggested_scope: string;
    confidence: string;
    effort: string;
    ranking_factors: Record<
        string,
        { points: number; reason: string } | number
    >;
    missing_fact_questions: string[];
    evidence: {
        quoted_excerpt?: string;
        diagnosis_mode?: string;
        origin_type?: string;
        additional_excerpt?: string | null;
        captured_at: string;
        windows: {
            current: { from: string; to: string };
            previous: { from: string; to: string };
        };
        search: { current: Search | null; previous: Search | null };
        overlap_pages: { id: string; title: string; url: string }[];
        limitations: string[];
    };
    dismissal_reason: string | null;
    diagnosed_at: string;
};
type Props = {
    pages: { id: string; title: string; snapshot_id: string | null }[];
    opportunities: Opportunity[];
    ongoing: {
        id: string;
        status: string;
        page_title: string;
        page_url: string;
    }[];
    history: Opportunity[];
    scan: { at: string; tracked_pages: number; notes: string[] } | null;
};
const kindLabels: Record<string, string> = {
    declining_performance: 'Search performance review',
    answer_gap: 'A buyer question to answer',
    missing_business_fact: 'A business detail to confirm',
};
const date = (value: string) => new Date(value).toLocaleString();

export default function OpportunityIndex({
    pages,
    opportunities,
    ongoing,
    history,
    scan,
}: Props) {
    const { auth } = usePage().props;
    const owner = auth.project?.role === 'owner';
    const [flaggedPage, setFlaggedPage] = useState('');

    return (
        <>
            <Head title="Plan" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Plan"
                    title="The next useful change"
                    description="Review up to five opportunities on your existing pages. Keep the work small, confirm the facts, and publish only after a separate approval."
                    context={
                        scan
                            ? `${scan.tracked_pages} pages checked`
                            : 'Ready when you are'
                    }
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href="/pages">Tracked pages</Link>
                            </Button>
                            <Button asChild variant="outline">
                                <Link href="/business-facts">
                                    Business facts
                                </Link>
                            </Button>
                            {owner && (
                                <Form action="/plan/refresh" method="post">
                                    {({ processing }) => (
                                        <Button disabled={processing}>
                                            {processing
                                                ? 'Checking evidence…'
                                                : 'Refresh opportunities'}
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    }
                />
                <div
                    className={`${workspacePanelClass} p-5 text-sm leading-6 text-muted-foreground`}
                >
                    <p>
                        {scan
                            ? `Last checked ${date(scan.at)}. The list uses saved page snapshots and the latest finished measurements.`
                            : 'Track your priority pages, then refresh opportunities. A Google connection improves the evidence; missing data stays unknown.'}
                    </p>
                    <p>
                        No forecast of extra sales is attached to these
                        suggestions. An observed decline can have many causes,
                        and leaving a working page alone is a valid decision.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-4">
                        <Link className="underline" href="/performance">
                            Search performance
                        </Link>
                        <Link className="underline" href="/calendar">
                            Article calendar
                        </Link>
                    </div>
                </div>
                {owner && pages.length > 0 && (
                    <details className={`${workspacePanelClass} p-5`}>
                        <summary className="cursor-pointer font-medium">
                            Flag unclear buyer information
                        </summary>
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            Copy a specific excerpt from a saved commercial page
                            and record the question it leaves unanswered. This
                            works in any language and creates a review item
                            without confirming its claims.
                        </p>
                        <Form
                            action="/opportunities"
                            method="post"
                            className="mt-4 grid gap-4 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="flagged-page">
                                            Commercial page
                                        </Label>
                                        <select
                                            id="flagged-page"
                                            name="site_page_id"
                                            required
                                            value={flaggedPage}
                                            onChange={(event) =>
                                                setFlaggedPage(
                                                    event.target.value,
                                                )
                                            }
                                            className="h-10 rounded-xl border bg-background px-3 text-sm"
                                        >
                                            <option value="">
                                                Choose a page
                                            </option>
                                            {pages.map((page) => (
                                                <option
                                                    key={page.id}
                                                    value={page.id}
                                                    disabled={!page.snapshot_id}
                                                >
                                                    {page.title}
                                                </option>
                                            ))}
                                        </select>
                                        <input
                                            type="hidden"
                                            name="snapshot_id"
                                            value={
                                                pages.find(
                                                    (page) =>
                                                        page.id === flaggedPage,
                                                )?.snapshot_id ?? ''
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors.site_page_id ??
                                                errors.snapshot_id
                                            }
                                        />
                                        {flaggedPage && (
                                            <Link
                                                href={`/pages/${flaggedPage}`}
                                                className="text-sm underline"
                                            >
                                                Open saved text to copy the
                                                evidence
                                            </Link>
                                        )}
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="quoted-excerpt">
                                            Exact page excerpt
                                        </Label>
                                        <Textarea
                                            id="quoted-excerpt"
                                            name="quoted_excerpt"
                                            required
                                            minLength={8}
                                            maxLength={1200}
                                        />
                                        <InputError
                                            message={errors.quoted_excerpt}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="additional-excerpt">
                                            Second excerpt, if the wording
                                            conflicts
                                        </Label>
                                        <Textarea
                                            id="additional-excerpt"
                                            name="additional_excerpt"
                                            minLength={8}
                                            maxLength={1200}
                                        />
                                        <InputError
                                            message={errors.additional_excerpt}
                                        />
                                    </div>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="buyer-question">
                                            What needs to be clarified?
                                        </Label>
                                        <Textarea
                                            id="buyer-question"
                                            name="question"
                                            required
                                            minLength={12}
                                            maxLength={1000}
                                        />
                                        <InputError message={errors.question} />
                                    </div>
                                    <Button
                                        disabled={processing || !flaggedPage}
                                        className="justify-self-start"
                                    >
                                        Save for review
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                )}
                {ongoing.length > 0 && (
                    <section
                        className={`${workspacePanelClass} p-5`}
                        aria-labelledby="ongoing-heading"
                    >
                        <h2
                            id="ongoing-heading"
                            className="text-lg font-semibold"
                        >
                            Changes already in review
                        </h2>
                        <ul className="mt-4 divide-y">
                            {ongoing.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {item.page_title}
                                        </p>
                                        <p className="text-xs break-all text-muted-foreground">
                                            {item.page_url}
                                        </p>
                                        <p className="mt-1 text-sm">
                                            {item.status.replaceAll('_', ' ')}
                                        </p>
                                    </div>
                                    <Button asChild variant="outline">
                                        <Link href={`/proposals/${item.id}`}>
                                            Review change
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {opportunities.length === 0 ? (
                    <section className={`${workspacePanelClass} p-6`}>
                        <h2 className="text-lg font-semibold">
                            {scan
                                ? 'Nothing new is worth changing on this evidence'
                                : 'Your first review starts with your pages'}
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            {scan
                                ? 'The checks did not produce another supported recommendation. Keep measuring, confirm missing business information, or refresh an old page snapshot. Low traffic is a reason for care, not an instruction to write more content.'
                                : 'Choose a service page, a pricing page and a useful article. Public page text is a snapshot, so confirm business claims separately before using them in a proposal.'}
                        </p>
                        {scan && (
                            <ul className="mt-4 list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                                {scan.notes.map((note) => (
                                    <li key={note}>{note}</li>
                                ))}
                            </ul>
                        )}
                    </section>
                ) : (
                    <section
                        className="grid gap-5"
                        aria-label="Recommended page reviews"
                    >
                        {opportunities.map((item, index) => (
                            <article
                                key={item.id}
                                className={`${workspacePanelClass} p-5 sm:p-6`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            {index + 1}.{' '}
                                            {kindLabels[item.kind] ?? item.kind}
                                        </p>
                                        <h2 className="mt-2 text-xl font-semibold">
                                            <Link
                                                href={`/pages/${item.page.id}`}
                                            >
                                                {item.page.title}
                                            </Link>
                                        </h2>
                                        <p className="mt-1 text-xs break-all text-muted-foreground">
                                            {item.page.url} · {item.page.locale}
                                        </p>
                                    </div>
                                    <span className="rounded-full border px-3 py-1 text-xs">
                                        {item.confidence} confidence ·{' '}
                                        {item.effort} change
                                    </span>
                                </div>
                                <p className="mt-4 leading-7">
                                    {item.diagnosed_issue}
                                </p>
                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                    {item.suggested_scope}
                                </p>
                                {item.missing_fact_questions.length > 0 && (
                                    <div className="mt-4 rounded-xl border bg-muted/30 p-4">
                                        <h3 className="font-medium">
                                            Confirm before proposing an answer
                                        </h3>
                                        <ul className="mt-2 list-disc space-y-2 pl-5 text-sm">
                                            {item.missing_fact_questions.map(
                                                (question) => (
                                                    <li key={question}>
                                                        {question}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                        <Link
                                            className="mt-3 inline-block text-sm underline"
                                            href="/business-facts"
                                        >
                                            Record a sourced business fact
                                        </Link>
                                    </div>
                                )}
                                <details className="mt-4 rounded-xl border p-4">
                                    <summary className="cursor-pointer text-sm font-medium">
                                        Why this is ranked here, and the
                                        evidence behind it
                                    </summary>
                                    <dl className="mt-4 grid gap-3 text-sm">
                                        {Object.entries(item.ranking_factors)
                                            .filter(
                                                ([, value]) =>
                                                    typeof value === 'object',
                                            )
                                            .map(
                                                ([key, value]) =>
                                                    typeof value ===
                                                        'object' && (
                                                        <div key={key}>
                                                            <dt className="font-medium capitalize">
                                                                {key.replaceAll(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </dt>
                                                            <dd className="text-muted-foreground">
                                                                {value.reason}
                                                            </dd>
                                                        </div>
                                                    ),
                                            )}
                                    </dl>
                                    <p className="mt-4 text-xs text-muted-foreground">
                                        Page captured{' '}
                                        {date(item.evidence.captured_at)}. These
                                        are review priorities, not predicted
                                        revenue.
                                    </p>
                                    {item.evidence.quoted_excerpt && (
                                        <div className="mt-4 grid gap-3 border-l-2 pl-4 text-sm">
                                            <p className="font-medium">
                                                Quoted from the pinned page
                                                snapshot
                                            </p>
                                            <blockquote>
                                                {item.evidence.quoted_excerpt}
                                            </blockquote>
                                            {item.evidence
                                                .additional_excerpt && (
                                                <blockquote>
                                                    {
                                                        item.evidence
                                                            .additional_excerpt
                                                    }
                                                </blockquote>
                                            )}
                                        </div>
                                    )}
                                    <div className="mt-3 overflow-x-auto">
                                        <table className="w-full text-left text-sm">
                                            <caption className="pb-2 text-left text-muted-foreground">
                                                Saved search observations
                                            </caption>
                                            <thead>
                                                <tr>
                                                    <th className="py-2 pr-4">
                                                        Window
                                                    </th>
                                                    <th className="pr-4">
                                                        Clicks
                                                    </th>
                                                    <th>Impressions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(
                                                    [
                                                        'current',
                                                        'previous',
                                                    ] as const
                                                ).map((window) => (
                                                    <tr
                                                        key={window}
                                                        className="border-t"
                                                    >
                                                        <th className="py-2 pr-4 font-normal">
                                                            {
                                                                item.evidence
                                                                    .windows[
                                                                    window
                                                                ].from
                                                            }{' '}
                                                            –{' '}
                                                            {
                                                                item.evidence
                                                                    .windows[
                                                                    window
                                                                ].to
                                                            }
                                                        </th>
                                                        <td>
                                                            {item.evidence
                                                                .search[window]
                                                                ?.clicks ??
                                                                'Unavailable'}
                                                        </td>
                                                        <td>
                                                            {item.evidence
                                                                .search[window]
                                                                ?.impressions ??
                                                                'Unavailable'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {item.evidence.overlap_pages.length > 0 ? (
                                        <div className="mt-4">
                                            <p className="text-sm font-medium">
                                                Similar existing pages to
                                                inspect before adding content
                                            </p>
                                            <ul className="mt-2 list-disc pl-5 text-sm">
                                                {item.evidence.overlap_pages.map(
                                                    (page) => (
                                                        <li key={page.id}>
                                                            <Link
                                                                href={`/pages/${page.id}`}
                                                                className="underline"
                                                            >
                                                                {page.title}
                                                            </Link>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ) : (
                                        <p className="mt-4 text-sm text-muted-foreground">
                                            No close title overlap was found
                                            among the known pages with a
                                            matching language or URL prefix.
                                            This is a basic check of up to 500
                                            pages, not a complete site audit.
                                        </p>
                                    )}
                                    <ul className="mt-4 list-disc space-y-2 pl-5 text-xs text-muted-foreground">
                                        {item.evidence.limitations.map(
                                            (note) => (
                                                <li key={note}>{note}</li>
                                            ),
                                        )}
                                    </ul>
                                </details>
                                {owner && (
                                    <div className="mt-5 grid gap-4">
                                        <Form
                                            action={`/opportunities/${item.id}/proposals`}
                                            method="post"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <Button
                                                        disabled={processing}
                                                    >
                                                        {processing
                                                            ? 'Preparing review…'
                                                            : 'Prepare a bounded proposal'}
                                                    </Button>
                                                    <InputError
                                                        message={
                                                            Object.values(
                                                                errors,
                                                            )[0]
                                                        }
                                                    />
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        Preparing a proposal
                                                        uses AI. It creates
                                                        reviewable suggestions;
                                                        it cannot publish a
                                                        change.
                                                    </p>
                                                </>
                                            )}
                                        </Form>
                                        <details>
                                            <summary className="cursor-pointer text-sm text-muted-foreground">
                                                Leave this page unchanged
                                            </summary>
                                            <Form
                                                action={`/opportunities/${item.id}/dismiss`}
                                                method="post"
                                                className="mt-3 flex flex-wrap items-end gap-3"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <div className="grid min-w-60 flex-1 gap-2">
                                                            <Label
                                                                htmlFor={`reason-${item.id}`}
                                                            >
                                                                Reason for
                                                                leaving it
                                                                unchanged
                                                            </Label>
                                                            <Input
                                                                id={`reason-${item.id}`}
                                                                name="reason"
                                                                required
                                                                minLength={3}
                                                                maxLength={2000}
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.reason
                                                                }
                                                            />
                                                        </div>
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Record decision
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </details>
                                    </div>
                                )}
                            </article>
                        ))}
                    </section>
                )}
                {history.length > 0 && (
                    <details className={`${workspacePanelClass} p-5`}>
                        <summary className="cursor-pointer font-medium">
                            Recent decisions and withdrawn suggestions
                        </summary>
                        <ul className="mt-3 divide-y">
                            {history.map((item) => (
                                <li key={item.id} className="py-3">
                                    <p className="font-medium">
                                        {item.page.title}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.status === 'dismissed'
                                            ? 'Left unchanged by the owner.'
                                            : 'No longer supported by the latest check.'}{' '}
                                        {item.dismissal_reason}
                                    </p>
                                    {owner &&
                                        item.status === 'dismissed' &&
                                        item.evidence.diagnosis_mode ===
                                            'reviewed_claim' && (
                                            <a
                                                className="mt-2 inline-block text-sm underline"
                                                href={
                                                    item.evidence
                                                        .origin_type ===
                                                    'fact_maintenance'
                                                        ? '/fact-maintenance'
                                                        : '/visibility'
                                                }
                                            >
                                                Recheck the original discrepancy
                                            </a>
                                        )}
                                    {owner &&
                                        item.status === 'dismissed' &&
                                        item.evidence.diagnosis_mode !==
                                            'reviewed_claim' && (
                                            <Form
                                                action={`/opportunities/${item.id}/reconsider`}
                                                method="post"
                                                className="mt-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <Button
                                                            disabled={
                                                                processing
                                                            }
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            Reconsider with
                                                            fresh evidence
                                                        </Button>
                                                        <InputError
                                                            message={
                                                                Object.values(
                                                                    errors,
                                                                )[0]
                                                            }
                                                        />
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </WorkspacePage>
        </>
    );
}
