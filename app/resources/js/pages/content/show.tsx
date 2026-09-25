import { Form, Head, Link, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ExternalLink,
    Send,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import { ArticlePublicationPanel } from '@/components/article-publication';
import type { ArticlePublication } from '@/components/article-publication';
import { ContextualAssistant } from '@/components/contextual-assistant';
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
import { cn } from '@/lib/utils';
import { approve, index, publish } from '@/routes/content';
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

type LocaleVersion = {
    id: string;
    locale: string;
    state: string;
    state_label: string;
    is_self: boolean;
};

type Props = {
    return_to: string | null;
    publication: ArticlePublication;
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
    locales: LocaleVersion[];
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
    return_to: returnTo,
    publication,
    item,
    brief,
    locales,
    deliveries,
    manual_channels,
    reasons,
    rewriting,
}: Props) {
    // Null until the operator asks, so the dialog is not mounted around an
    // article nobody is sending anywhere.
    const [sendingBack, setSendingBack] = useState<SendBackTarget | null>(null);
    const bodyHtml = demoteArticleHeadings(item.body_html);
    usePoll(15000, {
        only: ['item', 'publication', 'rewriting', 'deliveries'],
    });

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
                    eyebrow="Your content"
                    context={`${item.type_label} · ${item.locale}`}
                    title={item.title}
                    description={
                        item.target_query === null
                            ? 'An article for your website'
                            : `Helping customers with: ${item.target_query}`
                    }
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="rounded-full bg-background/70 shadow-sm"
                                asChild
                            >
                                <Link href={returnTo ?? index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    All content
                                </Link>
                            </Button>
                            {item.state === 'draft' && (
                                <>
                                    <Form
                                        action={approve(item.id).url}
                                        method="post"
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing, errors }) => (
                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        processing ||
                                                        !item.publishable ||
                                                        rewriting !== null
                                                    }
                                                >
                                                    Approve content
                                                </Button>
                                                {errors.approval && (
                                                    <p className="mt-1 max-w-xs text-xs text-destructive">
                                                        {errors.approval}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </Form>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setSendingBack({
                                                id: item.id,
                                                title: item.title,
                                            })
                                        }
                                    >
                                        Request changes
                                    </Button>
                                </>
                            )}
                            {item.state === 'approved' && (
                                <Button
                                    type="button"
                                    variant="secondary"
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
                            {((item.state === 'approved' &&
                                publication.schedule === null) ||
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
                                                    ? 'Sync published article'
                                                    : 'Publish now'}
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
                                <Button variant="ghost" asChild>
                                    <a
                                        href={item.public_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        View live article
                                        <ExternalLink
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </Button>
                            )}
                        </>
                    }
                />

                <LanguageVersionNav locales={locales} />

                <div id="publication" className="scroll-mt-6">
                    <ArticlePublicationPanel
                        approved={item.state === 'approved'}
                        itemId={item.id}
                        publication={publication}
                    />
                </div>
                {rewriting !== null && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner className="size-4" /> Avyo is preparing an
                        updated draft. It will go through the checks again
                        before publication.
                    </p>
                )}

                {item.factcheck.passed === false && (
                    <Card className="rounded-[1.5rem] border-chart-3/50 bg-chart-3/10 shadow-none">
                        <CardHeader className="flex-row items-start gap-3 space-y-0">
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0 text-foreground"
                                aria-hidden="true"
                            />
                            <div>
                                <CardTitle className="text-base">
                                    The fact-check found something
                                </CardTitle>
                                <CardDescription>
                                    {item.factcheck.required
                                        ? 'This topic needs extra accuracy checks. Fix these findings before approving it.'
                                        : 'Check these findings before this article is published.'}
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
                                <p className="mb-8 border-l-2 border-chart-1 bg-chart-1/5 px-5 py-4 text-base leading-relaxed">
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
                        className="min-w-0 space-y-4 rounded-2xl border p-4"
                        aria-labelledby="article-review-details"
                    >
                        <h2
                            id="article-review-details"
                            className="text-sm font-medium"
                        >
                            Quality checks and writing sources
                        </h2>
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
                                    Editorial context
                                </CardTitle>
                                <CardDescription>
                                    The business information and topics behind
                                    this article.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-5">
                                <section>
                                    <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                        Written from
                                    </p>
                                    <p className="mt-2 text-sm font-medium">
                                        {brief === null
                                            ? 'No brief recorded'
                                            : 'Your business brief'}
                                    </p>
                                    {brief !== null && (
                                        <>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {brief.is_active
                                                    ? 'Current brief'
                                                    : 'Superseded brief'}
                                            </p>
                                            <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                                {brief.tone}
                                            </p>
                                        </>
                                    )}
                                </section>

                                <section className="border-t pt-5">
                                    <p className="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                        Topics covered
                                    </p>
                                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                        Measured against the article text.
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        {Object.entries(
                                            item.entity_coverage,
                                        ).map(([entity, covered]) => (
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
                                        ))}
                                        {Object.keys(item.entity_coverage)
                                            .length === 0 && (
                                            <span className="text-sm text-muted-foreground">
                                                No topic checks recorded.
                                            </span>
                                        )}
                                    </div>
                                </section>
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

                <Card className={workspacePanelClass}>
                    <CardHeader>
                        <CardTitle>Delivery history</CardTitle>
                        <CardDescription>
                            Every attempt to publish this article to a channel.
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
                <ContextualAssistant
                    context={`Reviewing article "${item.title}" (content ID: ${item.id}, locale: ${item.locale}). ${item.public_url ? `Live page: ${item.public_url}` : 'This article has no live page yet.'}`}
                    label="Discuss this article"
                />
            </WorkspacePage>
        </>
    );
}

/**
 * Locale variants are peer documents, not a filter over this one. Keeping them
 * as real links preserves browser navigation while presenting the set where a
 * reviewer starts, rather than after the article in the details rail.
 */
function LanguageVersionNav({ locales }: { locales: LocaleVersion[] }) {
    return (
        <nav
            className={`${workspacePanelClass} grid gap-4 px-5 py-5 sm:px-6 lg:grid-cols-[minmax(12rem,0.35fr)_minmax(0,1fr)] lg:items-center`}
            aria-label="Article language versions"
        >
            <div>
                <p className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                    Language versions
                </p>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                    Switch markets without leaving the review.
                </p>
            </div>

            <div className="flex min-w-0 gap-2 overflow-x-auto pb-1 lg:justify-end">
                {locales.map((locale) => (
                    <Link
                        key={locale.id}
                        href={`/content/${locale.id}`}
                        aria-current={locale.is_self ? 'page' : undefined}
                        className={cn(
                            'flex min-h-12 min-w-40 shrink-0 items-center justify-between gap-4 rounded-xl border px-4 py-2.5 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            locale.is_self
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background/60 text-foreground hover:border-primary/40 hover:bg-accent',
                        )}
                    >
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold">
                                {localeDisplayName(locale.locale)}
                            </span>
                            <span
                                className={cn(
                                    'block text-xs',
                                    locale.is_self
                                        ? 'text-primary-foreground/70'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {locale.locale}
                            </span>
                        </span>
                        <span
                            className={cn(
                                'shrink-0 text-xs font-medium',
                                locale.is_self
                                    ? 'text-primary-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {locale.is_self ? 'Current' : locale.state_label}
                        </span>
                    </Link>
                ))}
            </div>
        </nav>
    );
}

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

function localeDisplayName(locale: string): string {
    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'language' }).of(locale) ??
            locale
        );
    } catch {
        return locale;
    }
}
