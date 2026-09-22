import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import type { Check, Fact, Version } from './types';

type Page = {
    id: string;
    canonical_url: string;
    title: string;
    locale: string;
};
type Impact = {
    id: string;
    site_page_id: string;
    origin_type: string;
    created_at: string;
    previous_fact: Version;
    new_fact: Version;
    evidence: {
        exact_quote?: string;
        proposal_id?: string;
        reason: string;
        recovered_at?: string | null;
    };
};

export default function MaintenanceIndex({
    pages,
    facts,
    checks,
    impacts,
}: {
    pages: Page[];
    facts: Fact[];
    checks: Check[];
    impacts: Impact[];
}) {
    const owner = usePage().props.auth.project?.role === 'owner';
    const form = useForm({
        request_key: crypto.randomUUID(),
        page_ids: [] as string[],
        fact_version_ids: facts
            .filter((f) => f.usable && f.current)
            .slice(0, 20)
            .map((f) => f.current!.id),
    });
    usePoll(15000, { only: ['checks', 'impacts'] });
    const toggle = (key: 'page_ids' | 'fact_version_ids', id: string) =>
        form.setData(
            key,
            form.data[key].includes(id)
                ? form.data[key].filter((value) => value !== id)
                : [...form.data[key], id],
        );

    return (
        <>
            <Head title="Maintain business facts" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Business facts"
                    title="Keep page statements current"
                    description="Check existing page statements against selected owner-confirmed facts. These are proposed assessments for your review, not new business knowledge."
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/business-facts">Business facts</Link>
                        </Button>
                    }
                />
                {owner && (
                    <form
                        className={`${workspacePanelClass} space-y-5 p-5`}
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/fact-maintenance', {
                                onSuccess: () =>
                                    form.setData(
                                        'request_key',
                                        crypto.randomUUID(),
                                    ),
                            });
                        }}
                    >
                        <p className="text-sm text-muted-foreground">
                            Each selected page starts a new paid check with a
                            fresh public capture. Select up to 20 pages and 20
                            current facts. Retries of this request reuse its
                            identity; an explicit recheck buys a new assessment.
                            Unselected facts and missing text remain unknown.
                        </p>
                        <div className="grid gap-6 md:grid-cols-2">
                            <fieldset className="space-y-3">
                                <legend className="mb-3 font-medium">
                                    Pages to inspect
                                </legend>
                                {pages.length === 0 && (
                                    <p className="text-sm">
                                        Track an existing page first in{' '}
                                        <Link
                                            href="/pages"
                                            className="underline"
                                        >
                                            Website pages
                                        </Link>
                                        .
                                    </p>
                                )}
                                {pages.map((page) => (
                                    <label
                                        className="flex gap-3 text-sm"
                                        key={page.id}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.page_ids.includes(
                                                page.id,
                                            )}
                                            onChange={() =>
                                                toggle('page_ids', page.id)
                                            }
                                            disabled={
                                                !form.data.page_ids.includes(
                                                    page.id,
                                                ) &&
                                                form.data.page_ids.length >= 20
                                            }
                                        />
                                        <span>
                                            {page.title || page.canonical_url}
                                            <span className="block text-xs text-muted-foreground">
                                                {page.locale} ·{' '}
                                                {page.canonical_url}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </fieldset>
                            <fieldset className="space-y-3">
                                <legend className="mb-3 font-medium">
                                    Confirmed evidence to use
                                </legend>
                                {facts
                                    .filter((f) => f.usable && f.current)
                                    .map((fact) => (
                                        <label
                                            className="flex gap-3 text-sm"
                                            key={fact.id}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={form.data.fact_version_ids.includes(
                                                    fact.current!.id,
                                                )}
                                                onChange={() =>
                                                    toggle(
                                                        'fact_version_ids',
                                                        fact.current!.id,
                                                    )
                                                }
                                                disabled={
                                                    !form.data.fact_version_ids.includes(
                                                        fact.current!.id,
                                                    ) &&
                                                    form.data.fact_version_ids
                                                        .length >= 20
                                                }
                                            />
                                            <span>
                                                {fact.name}
                                                <span className="block text-xs text-muted-foreground">
                                                    {fact.current!.statement}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                {!facts.some((f) => f.usable) && (
                                    <p className="text-sm">
                                        Confirm a current fact and its source
                                        before starting a check. A retracted
                                        fact does not supply a replacement.
                                    </p>
                                )}
                            </fieldset>
                        </div>
                        {Object.values(form.errors).map((error) => (
                            <p className="text-sm text-destructive" key={error}>
                                {error}
                            </p>
                        ))}
                        <Button
                            disabled={
                                form.processing ||
                                !form.data.page_ids.length ||
                                !form.data.fact_version_ids.length
                            }
                        >
                            Check selected pages
                        </Button>
                    </form>
                )}
                <section className={`${workspacePanelClass} p-5`}>
                    <h2 className="font-semibold">
                        Earlier uses affected by changed facts
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        These historical dependencies need a current page check.
                        A changed fact does not prove the old statement remains
                        live. Removed wording does not by itself establish a
                        correct replacement.
                    </p>
                    {impacts.length === 0 ? (
                        <p className="mt-4 text-sm">
                            No recorded earlier usage has been flagged. Pages
                            that have not been checked remain unknown.
                        </p>
                    ) : (
                        impacts.map((impact) => (
                            <article
                                key={impact.id}
                                className="mt-4 space-y-2 border-t pt-4 text-sm"
                            >
                                <p className="font-medium">
                                    {impact.origin_type === 'publication'
                                        ? 'Published proposal reference'
                                        : 'Earlier checked claim'}{' '}
                                    ·{' '}
                                    {new Date(
                                        impact.created_at,
                                    ).toLocaleString()}
                                </p>
                                {impact.evidence.exact_quote && (
                                    <blockquote className="border-l-2 pl-3">
                                        {impact.evidence.exact_quote}
                                    </blockquote>
                                )}
                                <p>
                                    Previously used:{' '}
                                    {impact.previous_fact?.statement}
                                </p>
                                <p>
                                    New version ({impact.new_fact?.status}):{' '}
                                    {impact.new_fact?.statement}
                                </p>
                                <p className="text-muted-foreground">
                                    {impact.evidence.reason}
                                    {impact.evidence.recovered_at &&
                                        ' This publication was subsequently recovered; inspect the current page.'}
                                </p>
                                <Link
                                    className="underline"
                                    href={`/pages/${impact.site_page_id}`}
                                >
                                    Inspect the affected page
                                </Link>
                                {impact.evidence.proposal_id && (
                                    <Link
                                        className="ml-4 underline"
                                        href={`/proposals/${impact.evidence.proposal_id}`}
                                    >
                                        Publication history
                                    </Link>
                                )}
                            </article>
                        ))
                    )}
                </section>
                <section className={`${workspacePanelClass} p-5`}>
                    <h2 className="font-semibold">Check history</h2>
                    {checks.length === 0 && (
                        <p className="mt-3 text-sm">
                            No page statements have been checked yet.
                        </p>
                    )}
                    {checks.map((check) => (
                        <Link
                            key={check.id}
                            href={`/fact-maintenance/${check.id}`}
                            className="mt-3 block rounded border p-3 text-sm"
                        >
                            <span className="font-medium">
                                {check.specification.canonical_url}
                            </span>
                            <span className="block text-muted-foreground">
                                {check.specification.locale} · {check.status} ·{' '}
                                {new Date(check.created_at).toLocaleString()}
                            </span>
                            {check.reason && (
                                <span className="block text-muted-foreground">
                                    {check.reason}
                                </span>
                            )}
                        </Link>
                    ))}
                </section>
            </WorkspacePage>
        </>
    );
}
