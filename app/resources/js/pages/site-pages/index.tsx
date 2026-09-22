import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index as factsIndex } from '@/routes/business-facts';
import { index as articlesIndex } from '@/routes/content';
import { discover, index, show, store } from '@/routes/pages';
import { edit as editProject } from '@/routes/projects';
import type { Paginated } from '@/types';
import type { SitePage } from './types';

type Candidate = {
    id: string;
    url: string;
    title: string;
    is_article: boolean;
};
type Props = {
    pages: Paginated<SitePage>;
    candidates: Candidate[];
    locales: string[];
    defaultLocale: string;
};
const selectClass = 'h-10 rounded-xl border bg-background px-3 text-sm';

export default function SitePagesIndex({
    pages,
    candidates,
    locales,
    defaultLocale,
}: Props) {
    const [url, setUrl] = useState('');
    const [kind, setKind] = useState('commercial');
    const { auth, errors } = usePage().props;
    const owner = auth.project?.role === 'owner';
    const selectCandidate = (candidate: Candidate) => {
        setUrl(candidate.url);
        setKind(candidate.is_article ? 'editorial' : 'commercial');
        document.getElementById('page-url')?.focus();
    };

    return (
        <>
            <Head title="Website content" />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Content"
                    title="Your website pages"
                    description="Track the existing pages that matter to customers. Keep public snapshots, reviewed changes, and business facts separate."
                    context={`${pages.total} tracked`}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={factsIndex()}>Business facts</Link>
                            </Button>
                            <Button asChild variant="ghost">
                                <Link href={articlesIndex()}>
                                    Generated articles
                                </Link>
                            </Button>
                        </>
                    }
                />
                {owner && (
                    <section
                        className={`${workspacePanelClass} p-5 sm:p-6`}
                        aria-labelledby="import-heading"
                    >
                        <h2
                            id="import-heading"
                            className="text-lg font-semibold"
                        >
                            Track an existing page
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            Start with a service, pricing, or article page on
                            your own website. Importing reads the public page;
                            it does not change your website or confirm its
                            claims as facts.
                        </p>
                        <Form
                            action={store()}
                            method="post"
                            className="mt-5 grid gap-4 sm:grid-cols-2"
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors: formErrors }) => (
                                <>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="page-url">
                                            Page URL
                                        </Label>
                                        <Input
                                            id="page-url"
                                            name="url"
                                            type="url"
                                            required
                                            value={url}
                                            onChange={(event) =>
                                                setUrl(event.target.value)
                                            }
                                            placeholder="https://your-site.com/services/cleaning"
                                        />
                                        <InputError message={formErrors.url} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="page-locale">
                                            Language
                                        </Label>
                                        <select
                                            id="page-locale"
                                            name="locale"
                                            defaultValue={defaultLocale}
                                            className={selectClass}
                                        >
                                            {locales.map((locale) => (
                                                <option
                                                    key={locale}
                                                    value={locale}
                                                >
                                                    {locale}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={formErrors.locale}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="page-kind">
                                            Page purpose
                                        </Label>
                                        <select
                                            id="page-kind"
                                            name="kind"
                                            value={kind}
                                            onChange={(event) =>
                                                setKind(event.target.value)
                                            }
                                            className={selectClass}
                                        >
                                            <option value="commercial">
                                                Service, pricing, or business
                                                information
                                            </option>
                                            <option value="editorial">
                                                Existing article or guide
                                            </option>
                                            <option value="other">
                                                Other page
                                            </option>
                                        </select>
                                        <InputError message={formErrors.kind} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && (
                                                <Spinner className="size-4" />
                                            )}
                                            Track and capture page
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                <section
                    className={`${workspacePanelClass} overflow-hidden`}
                    aria-labelledby="tracked-heading"
                >
                    <header className="border-b px-5 py-4">
                        <h2 id="tracked-heading" className="font-semibold">
                            Tracked pages
                        </h2>
                    </header>
                    {pages.data.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-muted-foreground">
                            No pages selected yet. Import a page above or
                            discover URLs from your sitemap below.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {pages.data.map((page) => (
                                <li key={page.id}>
                                    <Link
                                        href={show(page.id)}
                                        className="flex flex-wrap items-start justify-between gap-3 px-5 py-4 hover:bg-muted/30"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {page.title}
                                            </p>
                                            <p className="mt-1 text-xs break-all text-muted-foreground">
                                                {page.canonical_url ?? page.url}
                                            </p>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            <p>
                                                {page.locale} · {page.kind}
                                            </p>
                                            <p className="mt-1">
                                                {page.snapshot_at
                                                    ? `Read ${new Date(page.snapshot_at).toLocaleDateString()}`
                                                    : 'No snapshot yet'}
                                            </p>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <Pagination page={pages} />
                {owner && (
                    <section className={`${workspacePanelClass} p-5 sm:p-6`}>
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="font-semibold">
                                    Discover your website
                                </h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    Read up to five sitemaps and 200 URLs, then
                                    choose which pages to track.
                                </p>
                            </div>
                            <Form
                                action={discover()}
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        {processing && (
                                            <Spinner className="size-4" />
                                        )}
                                        Read sitemap
                                    </Button>
                                )}
                            </Form>
                        </div>
                        <InputError message={errors.sitemap} />
                        {auth.project && (
                            <Link
                                href={editProject(auth.project.id)}
                                className="mt-3 inline-block text-xs text-muted-foreground underline underline-offset-4"
                            >
                                Website and sitemap settings
                            </Link>
                        )}
                        {candidates.length > 0 && (
                            <details className="mt-5 border-t pt-4">
                                <summary className="cursor-pointer text-sm font-medium">
                                    {candidates.length} discovered or paused
                                    pages
                                </summary>
                                <ul className="mt-3 max-h-96 divide-y overflow-auto">
                                    {candidates.map((candidate) => (
                                        <li
                                            key={candidate.id}
                                            className="flex items-center justify-between gap-4 py-3"
                                        >
                                            <span className="min-w-0 text-xs break-all text-muted-foreground">
                                                {candidate.url}
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                type="button"
                                                onClick={() =>
                                                    selectCandidate(candidate)
                                                }
                                            >
                                                Select
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </section>
                )}
            </WorkspacePage>
        </>
    );
}
SitePagesIndex.layout = {
    breadcrumbs: [{ title: 'Website content', href: index() }],
};
