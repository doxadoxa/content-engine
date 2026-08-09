import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ExternalLink,
    FileText,
    Send,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import { SendBackDialog } from '@/components/send-back-dialog';
import type { Reason, SendBackTarget } from '@/components/send-back-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index, publish } from '@/routes/content';
import type { ArticleData, ScoreCheck } from './score-panel';
import { ArticleDataPanel, ScorePanel } from './score-panel';

type Delivery = {
    id: string;
    delivery_id: string;
    status: string;
    status_label: string;
    response_code: number | null;
    attempts: number;
    error: string | null;
    created_at: string | null;
};

type Props = {
    item: {
        id: string;
        title: string;
        slug: string;
        locale: string;
        state: string;
        state_label: string;
        type_label: string;
        target_query: string | null;
        summary: string | null;
        body_html: string | null;
        outline: string[];
        json_ld: Record<string, unknown>;
        faq_json_ld: Record<string, unknown>;
        quotable_blocks: string[];
        hero: { url: string; alt: string } | null;
        score: number;
        publishable: boolean;
        blocking: string[];
        checks: ScoreCheck[];
        data: ArticleData;
        entity_coverage: Record<string, boolean>;
        factcheck: {
            passed?: boolean;
            findings?: string[];
            required?: boolean;
        };
        author: Record<string, string>;
        review: { reason?: string; note?: string; by?: string; at?: string };
        public_url: string | null;
        cluster: string | null;
        intent: string | null;
    };
    brief: {
        id: string;
        version: number;
        is_active: boolean;
        tone: string;
    } | null;
    locales: { id: string; locale: string; state: string; is_self: boolean }[];
    derivatives: {
        id: string;
        title: string;
        type_label: string;
        state: string;
    }[];
    deliveries: Delivery[];
    manual_channels: number;
    reasons: Reason[];
    rewriting: { done: number; total: number } | null;
};

/**
 * The unit card: everything about one piece of work in one place, so an
 * approval decision does not need a second tab.
 */
export default function ContentShow({
    item,
    brief,
    locales,
    derivatives,
    deliveries,
    manual_channels,
    reasons,
    rewriting,
}: Props) {
    // Null until the operator asks, so the dialog is not mounted around an
    // article nobody is sending anywhere.
    const [sendingBack, setSendingBack] = useState<SendBackTarget | null>(null);
    const missing = Object.entries(item.entity_coverage)
        .filter(([, covered]) => !covered)
        .map(([entity]) => entity);
    const bodyHtml = demoteArticleHeadings(item.body_html);

    return (
        <>
            <Head title={item.title} />

            <SendBackDialog
                item={sendingBack}
                reasons={reasons}
                onClose={() => setSendingBack(null)}
            />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="Article workspace"
                    context={`${item.locale} · ${item.state_label}`}
                    title={item.title}
                    description={
                        item.target_query === null
                            ? item.slug
                            : `Target query: ${item.target_query}`
                    }
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="rounded-full bg-background/70 shadow-sm"
                                asChild
                            >
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Content plan
                                </Link>
                            </Button>
                            <Badge className="h-9 rounded-full px-3">
                                {item.state_label}
                            </Badge>
                            {/*
                              A unit being rewritten is a `draft`, exactly like
                              one that is finished and waiting — so the state
                              badge alone made sending an article back look like
                              a button that had done nothing.
                            */}
                            {rewriting !== null && (
                                <Badge
                                    variant="outline"
                                    className="h-9 gap-2 rounded-full border-violet-500/20 bg-background/70 px-3 text-violet-700 dark:text-violet-200"
                                >
                                    <Spinner className="size-3.5" />
                                    Rewriting · {rewriting.done} of{' '}
                                    {rewriting.total}
                                </Badge>
                            )}
                            <Badge
                                variant="outline"
                                className="h-9 rounded-full bg-background/70 px-3"
                            >
                                {item.type_label}
                            </Badge>
                            {item.state === 'approved' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-full bg-background/70 shadow-sm"
                                    onClick={() =>
                                        setSendingBack({
                                            id: item.id,
                                            title: item.title,
                                        })
                                    }
                                >
                                    <Undo2
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Send back
                                </Button>
                            )}
                            {(item.state === 'approved' ||
                                item.state === 'published') && (
                                <Form
                                    action={publish(item.id).url}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <div className="flex flex-col items-end gap-1">
                                            <Button
                                                type="submit"
                                                className="rounded-full"
                                                disabled={
                                                    processing ||
                                                    manual_channels === 0
                                                }
                                            >
                                                <Send
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {item.state === 'published'
                                                    ? 'Sync'
                                                    : 'Publish to'}{' '}
                                                {manual_channels}{' '}
                                                {manual_channels === 1
                                                    ? 'channel'
                                                    : 'channels'}
                                            </Button>
                                            {errors.publishing && (
                                                <p className="max-w-xs text-right text-xs text-destructive">
                                                    {errors.publishing}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </Form>
                            )}
                            {item.public_url !== null && (
                                <a
                                    href={item.public_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex h-11 items-center gap-1.5 rounded-full border bg-background/70 px-4 text-sm font-medium shadow-sm transition-colors hover:bg-accent"
                                >
                                    Live
                                    <ExternalLink
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                </a>
                            )}
                        </>
                    }
                />

                <section
                    className={`${workspacePanelClass} flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-4 text-sm sm:px-6`}
                    aria-label="Article overview"
                >
                    <span className="flex items-center gap-2 font-medium">
                        <span className="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300">
                            <FileText className="size-4" aria-hidden="true" />
                        </span>
                        Editorial review
                    </span>
                    <span className="text-muted-foreground">
                        Score{' '}
                        <strong className="font-semibold text-foreground">
                            {item.score}/100
                        </strong>
                    </span>
                    <span className="text-muted-foreground">
                        {locales.length}{' '}
                        {locales.length === 1 ? 'language' : 'languages'}
                    </span>
                    <span className="text-muted-foreground">
                        {deliveries.length}{' '}
                        {deliveries.length === 1 ? 'delivery' : 'deliveries'}
                    </span>
                </section>

                {item.factcheck.passed === false && (
                    <Card className="rounded-[1.5rem] border-amber-500/50 bg-amber-50/50 shadow-none dark:bg-amber-950/20">
                        <CardHeader className="flex-row items-start gap-3 space-y-0">
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0 text-amber-600"
                                aria-hidden="true"
                            />
                            <div>
                                <CardTitle className="text-base">
                                    The fact-check found something
                                </CardTitle>
                                <CardDescription>
                                    {item.factcheck.required
                                        ? 'This project is YMYL, so this draft cannot be approved until it is fixed.'
                                        : 'Reviewable, but somebody should look.'}
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <ul className="list-disc pl-5 text-sm">
                                {(item.factcheck.findings ?? []).map(
                                    (finding) => (
                                        <li key={finding}>{finding}</li>
                                    ),
                                )}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {item.review.reason !== undefined && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Sent back: {item.review.reason}
                            </CardTitle>
                            <CardDescription>
                                {item.review.note}
                                {item.review.by !== undefined &&
                                    ` — ${item.review.by}`}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <UndeliveredNotice deliveries={deliveries} />

                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card
                        className={`${workspacePanelClass} min-w-0 gap-0 overflow-hidden py-0`}
                    >
                        {item.hero !== null && (
                            <img
                                src={item.hero.url}
                                alt={item.hero.alt}
                                className="aspect-[1200/630] w-full border-b object-cover"
                            />
                        )}

                        <CardContent className="px-5 py-7 sm:px-8 sm:py-9 lg:px-10">
                            {item.summary !== null && (
                                /* The one-sentence answer, as a callout rather
                                   than as small grey text under a heading: it
                                   is the thing an AI answer lifts, and the
                                   first thing a reviewer reads. */
                                <p className="mb-8 rounded-r-2xl border-l-2 border-violet-500/50 bg-violet-500/[0.045] px-5 py-4 text-base leading-relaxed italic">
                                    {item.summary}
                                </p>
                            )}

                            {bodyHtml === null ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing written yet.
                                </p>
                            ) : (
                                <div
                                    className="mx-auto prose max-w-3xl dark:prose-invert"
                                    /* Written by our own generation pipeline
                                       and rendered from our own markdown, not
                                       from anything a reader supplied. */
                                    dangerouslySetInnerHTML={{
                                        __html: bodyHtml,
                                    }}
                                />
                            )}
                        </CardContent>
                    </Card>

                    <aside
                        className="flex min-w-0 flex-col gap-4"
                        aria-label="Article review details"
                    >
                        <ScorePanel
                            score={item.score}
                            checks={item.checks}
                            publishable={item.publishable}
                            blocking={item.blocking}
                        />

                        <ArticleDataPanel data={item.data} />

                        <Card className={workspacePanelClass}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Languages
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-1">
                                {locales.map((locale) => (
                                    <Link
                                        key={locale.id}
                                        href={`/content/${locale.id}`}
                                    >
                                        <Badge
                                            variant={
                                                locale.is_self
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {locale.locale}
                                        </Badge>
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>

                        <Card className={workspacePanelClass}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Written from
                                </CardTitle>
                                <CardDescription>
                                    {brief === null
                                        ? 'No brief recorded.'
                                        : `Brief v${brief.version}${brief.is_active ? ' (live)' : ' (superseded)'}`}
                                </CardDescription>
                            </CardHeader>
                            {brief !== null && (
                                <CardContent className="text-sm text-muted-foreground">
                                    {brief.tone}
                                </CardContent>
                            )}
                        </Card>

                        <Card className={workspacePanelClass}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Entity coverage
                                </CardTitle>
                                <CardDescription>
                                    Measured against the text, not declared.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-1">
                                {Object.entries(item.entity_coverage).map(
                                    ([entity, covered]) => (
                                        <Badge
                                            key={entity}
                                            variant={
                                                covered
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                        >
                                            {entity}
                                        </Badge>
                                    ),
                                )}
                                {missing.length === 0 &&
                                    Object.keys(item.entity_coverage).length ===
                                        0 && (
                                        <span className="text-sm text-muted-foreground">
                                            No entities recorded.
                                        </span>
                                    )}
                            </CardContent>
                        </Card>
                    </aside>
                </div>

                {item.quotable_blocks.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardContent className="pt-6">
                            {/* Collapsed. Twenty-five standalone paragraphs
                                listed in full are longer than the article they
                                came from, and they buried it. The count is the
                                useful part; the text is for when somebody
                                actually wants to check it. */}
                            <details className="group">
                                <summary className="cursor-pointer list-none text-sm font-medium">
                                    <span className="group-open:hidden">
                                        Show
                                    </span>
                                    <span className="hidden group-open:inline">
                                        Hide
                                    </span>{' '}
                                    the {item.quotable_blocks.length} quotable
                                    blocks
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        paragraphs an AI answer can lift without
                                        the article around them
                                    </span>
                                </summary>
                                <div className="mt-4 flex flex-col gap-3">
                                    {item.quotable_blocks.map((block) => (
                                        <p
                                            key={block}
                                            className="border-l-2 border-primary/40 pl-3 text-sm"
                                        >
                                            {block}
                                        </p>
                                    ))}
                                </div>
                            </details>
                        </CardContent>
                    </Card>
                )}

                {derivatives.length > 0 && (
                    <Card className={workspacePanelClass}>
                        <CardHeader>
                            <CardTitle>Derived posts</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {derivatives.map((child) => (
                                <Link
                                    key={child.id}
                                    href={`/content/${child.id}`}
                                    className="flex items-center justify-between rounded-md border p-2 text-sm hover:bg-accent"
                                >
                                    <span>{child.title}</span>
                                    <Badge variant="outline">
                                        {child.type_label}
                                    </Badge>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle>Deliveries</CardTitle>
                        <CardDescription>
                            Every attempt to hand this unit to a channel.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2">
                        {deliveries.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Not delivered anywhere yet.
                            </p>
                        ) : (
                            deliveries.map((delivery) => (
                                <div
                                    key={delivery.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm"
                                >
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {delivery.delivery_id}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        {delivery.response_code !== null && (
                                            <span className="text-muted-foreground">
                                                {delivery.response_code}
                                            </span>
                                        )}
                                        <Badge
                                            variant={
                                                delivery.status === 'delivered'
                                                    ? 'default'
                                                    : delivery.status ===
                                                        'dead_letter'
                                                      ? 'destructive'
                                                      : 'secondary'
                                            }
                                        >
                                            {delivery.status_label}
                                        </Badge>
                                    </span>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </WorkspacePage>
        </>
    );
}

/**
 * The workspace title is the document h1. Generated article HTML can also
 * start with an h1, so demote article-body h1s when previewing it inside this
 * page to preserve one coherent heading hierarchy. Public delivery is
 * unchanged.
 */
function demoteArticleHeadings(html: string | null): string | null {
    if (html === null) {
        return null;
    }

    return html
        .replace(/<h1(\b[^>]*)>/gi, '<h2$1>')
        .replace(/<\/h1>/gi, '</h2>');
}

ContentShow.layout = {
    breadcrumbs: [{ title: 'Content', href: index() }],
};

/**
 * Said at the top, where the state badge is.
 *
 * The deliveries log has always been on this page, at the bottom of a very long
 * one. An operator reads "Published" beside the title and stops — which is
 * exactly what happened when an endpoint answered 405 and the article never
 * left the building.
 */
function UndeliveredNotice({ deliveries }: { deliveries: Delivery[] }) {
    const failed = deliveries.filter((d) => d.status === 'dead_letter');
    const delivered = deliveries.some((d) => d.status === 'delivered');

    if (failed.length === 0 || delivered) {
        return null;
    }

    return (
        <Card className="rounded-[1.5rem] border-destructive/50 bg-destructive/[0.035] shadow-none">
            <CardHeader className="flex-row items-start gap-3 space-y-0">
                <AlertTriangle
                    className="mt-0.5 size-5 shrink-0 text-destructive"
                    aria-hidden="true"
                />
                <div>
                    <CardTitle className="text-base">
                        This never reached your site
                    </CardTitle>
                    <CardDescription>
                        {failed[0].error ??
                            'The receiver refused the delivery.'}
                        {failed[0].response_code !== null &&
                            ` (HTTP ${failed[0].response_code})`}
                        . Check the endpoint on the channel — it has to be a URL
                        that accepts a POST, not a page on your site.
                    </CardDescription>
                </div>
            </CardHeader>
        </Card>
    );
}
