import { Form, Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ContextualAssistant } from '@/components/contextual-assistant';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { show as articleShow } from '@/routes/content';
import { capture, index, untrack } from '@/routes/pages';
import type { SitePage, Snapshot } from './types';

type Props = {
    page: SitePage;
    snapshots: Snapshot[];
    connections: { id: string; name: string; type: string }[];
    cms: {
        channel_id: string | null;
        object_id: string | null;
        object_type: string | null;
        latest: {
            id: string;
            revision: string;
            editable_fields: string[];
            metadata: Record<string, unknown>;
        } | null;
    };
};
export default function SitePageShow({
    page,
    snapshots,
    connections,
    cms,
}: Props) {
    const [selected, setSelected] = useState(snapshots[0]?.id ?? '');
    const snapshot =
        snapshots.find((entry) => entry.id === selected) ?? snapshots[0];
    const { errors, auth } = usePage().props;
    const owner = auth.project?.role === 'owner';

    return (
        <>
            <Head title={page.title} />
            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Website page"
                    title={page.title}
                    description={page.canonical_url ?? page.url}
                    context={`${page.locale ?? 'Unknown language'} · ${page.kind}`}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={index()}>All pages</Link>
                            </Button>
                            {owner && page.tracked_at && (
                                <Form
                                    action={capture(page.id)}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                    onSuccess={(response) => {
                                        const captured = response.props
                                            .snapshots as Snapshot[];
                                        setSelected(captured[0]?.id ?? '');
                                    }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && (
                                                <Spinner className="size-4" />
                                            )}
                                            Capture current page
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </>
                    }
                />
                <InputError
                    message={errors.url ?? errors.locale ?? errors.cms}
                />
                <div className="flex flex-wrap gap-3">
                    <Button asChild variant="outline">
                        <a
                            href={page.canonical_url ?? page.url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Open live page
                        </a>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={factsIndex()}>Review business facts</Link>
                    </Button>
                    {page.content_item_id && (
                        <Button asChild variant="ghost">
                            <Link href={articleShow(page.content_item_id)}>
                                Generated article record
                            </Link>
                        </Button>
                    )}
                </div>
                {owner && page.tracked_at && (
                    <CmsBinding
                        page={page}
                        cms={cms}
                        connections={connections}
                    />
                )}
                {snapshot ? (
                    <section className={`${workspacePanelClass} p-5 sm:p-6`}>
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    Captured source
                                </h2>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {page.snapshot_count} snapshots retained.
                                    Showing the latest {snapshots.length}.
                                </p>
                            </div>
                            <label className="grid gap-1 text-xs text-muted-foreground">
                                Snapshot
                                <select
                                    value={snapshot.id}
                                    onChange={(event) =>
                                        setSelected(event.target.value)
                                    }
                                    className="h-10 max-w-full rounded-xl border bg-background px-3 text-sm text-foreground"
                                >
                                    {snapshots.map((entry) => (
                                        <option key={entry.id} value={entry.id}>
                                            {new Date(
                                                entry.captured_at,
                                            ).toLocaleString()}{' '}
                                            · {entry.source_kind}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <div className="mt-5 rounded-xl border bg-muted/30 p-4 text-sm leading-6">
                            <Badge variant="outline">
                                {snapshot.source_kind === 'public'
                                    ? 'Public observation'
                                    : 'CMS source'}
                            </Badge>
                            <p className="mt-2">
                                {snapshot.editable_fields.length === 0
                                    ? 'This snapshot records what the public page says. It is not editable CMS source. Publishing requires a compatible connection or an assisted, verified handoff.'
                                    : `Supported editable fields: ${snapshot.editable_fields.join(', ')}.`}
                            </p>
                            <p className="mt-2 text-muted-foreground">
                                Page text is not a confirmed business fact.
                                Confirm factual claims separately before using
                                them in a proposal.
                            </p>
                        </div>
                        <dl className="mt-6 space-y-4">
                            <div>
                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Title
                                </dt>
                                <dd className="mt-2 text-sm">
                                    {snapshot.fields.title || 'No title found'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Meta description
                                </dt>
                                <dd className="mt-2 text-sm">
                                    {snapshot.fields.description ||
                                        'No meta description found'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Page text
                                </dt>
                                <dd className="mt-2 max-h-[36rem] overflow-auto text-sm leading-7 whitespace-pre-wrap">
                                    {snapshot.fields.body_text ||
                                        'No readable body was returned. This page may require browser rendering; do not treat the missing text as missing website content.'}
                                </dd>
                            </div>
                        </dl>
                        <details className="mt-6 border-t pt-4">
                            <summary className="cursor-pointer text-xs font-medium">
                                Source and revision details
                            </summary>
                            <p className="mt-3 text-xs break-all text-muted-foreground">
                                Read from {snapshot.source_url}
                            </p>
                            <p className="mt-2 font-mono text-xs break-all">
                                {snapshot.revision}
                            </p>
                            <pre className="mt-3 max-h-96 overflow-auto rounded-xl bg-muted/40 p-3 text-xs break-all whitespace-pre-wrap">
                                {snapshot.fields.body_html}
                            </pre>
                        </details>
                    </section>
                ) : (
                    <section className={`${workspacePanelClass} p-6`}>
                        <p className="text-sm text-muted-foreground">
                            No snapshot has been captured. Track this page from
                            the page list to read its current public content.
                        </p>
                    </section>
                )}
                <ContextualAssistant
                    label="Discuss this page"
                    context={`Reviewing website page ${page.canonical_url ?? page.url}, locale ${page.locale ?? 'unknown'}, tracked page ID ${page.id}. ${snapshot ? `Snapshot ${snapshot.id} captured ${snapshot.captured_at}. Public text is not owner-confirmed fact.` : 'No snapshot is available.'}`}
                />
                {owner && page.tracked_at && (
                    <Form action={untrack(page.id)} method="post">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="ghost"
                                disabled={processing}
                            >
                                Pause tracking · retain snapshots and history
                            </Button>
                        )}
                    </Form>
                )}
            </WorkspacePage>
        </>
    );
}
SitePageShow.layout = {
    breadcrumbs: [{ title: 'Website content', href: index() }],
};

function CmsBinding({
    page,
    cms,
    connections,
}: Pick<Props, 'page' | 'cms' | 'connections'>) {
    const form = useForm({
        channel_id: cms.channel_id ?? connections[0]?.id ?? '',
        object_id: cms.object_id ?? '',
        object_type: cms.object_type ?? 'page',
    });
    const capture = useForm({});

    return (
        <section className={`${workspacePanelClass} space-y-4 p-5`}>
            <h2 className="text-lg font-semibold">Editable website source</h2>
            <p className="text-sm text-muted-foreground">
                Public HTML is an observation. Bind the exact website object to
                read its editable fields and revision before preparing a native
                change.
            </p>
            {connections.length === 0 ? (
                <Button asChild variant="outline">
                    <Link href="/channels">
                        Connect WordPress or a compatible receiver
                    </Link>
                </Button>
            ) : (
                <form
                    className="grid gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(`/pages/${page.id}/cms-binding`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Label htmlFor="cms-channel">Website connection</Label>
                    <select
                        id="cms-channel"
                        className="rounded-md border bg-background p-2"
                        value={form.data.channel_id}
                        onChange={(event) =>
                            form.setData('channel_id', event.target.value)
                        }
                    >
                        {connections.map((connection) => (
                            <option key={connection.id} value={connection.id}>
                                {connection.name}
                            </option>
                        ))}
                    </select>
                    <Label htmlFor="cms-object-type">Object type</Label>
                    <select
                        id="cms-object-type"
                        className="rounded-md border bg-background p-2"
                        value={form.data.object_type}
                        onChange={(event) =>
                            form.setData('object_type', event.target.value)
                        }
                    >
                        {['page', 'post', 'service', 'article'].map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                    <Label htmlFor="cms-object">Exact object ID</Label>
                    <Input
                        id="cms-object"
                        required
                        value={form.data.object_id}
                        onChange={(event) =>
                            form.setData('object_id', event.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        WordPress uses its numeric page or post ID. A custom
                        receiver supplies its exact object-and-language key.
                    </p>
                    {Object.values(form.errors).map((error, index) => (
                        <InputError key={index} message={error} />
                    ))}
                    <Button
                        disabled={form.processing}
                        type="submit"
                        className="justify-self-start"
                    >
                        Bind and read editable source
                    </Button>
                </form>
            )}
            {cms.latest && (
                <div className="space-y-2 border-t pt-3">
                    <p className="text-sm">
                        Supported editable fields:{' '}
                        {cms.latest.editable_fields.join(', ') ||
                            'none — use assisted publication'}
                        .
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {String(
                            cms.latest.metadata.unsupported_reason ??
                                cms.latest.metadata.body_unsupported_reason ??
                                'The receiver will recheck this source revision before applying an approved change.',
                        )}
                    </p>
                    <Button
                        variant="outline"
                        disabled={capture.processing}
                        onClick={() =>
                            capture.post(
                                `/pages/${page.id}/editable-snapshots`,
                                { preserveScroll: true },
                            )
                        }
                    >
                        Refresh editable source
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Request a new proposal revision after a source change.
                        Existing approvals stay tied to their original source.
                    </p>
                </div>
            )}
        </section>
    );
}
