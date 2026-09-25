import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { publicationLabels } from '@/components/article-publication';
import type { ArticlePublication } from '@/components/article-publication';
import { ContentActions } from '@/components/content-actions';
import { Pagination } from '@/components/pagination';
import { PlanViews } from '@/components/plan-views';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { index as approvalsIndex } from '@/routes/approvals';
import { index, show } from '@/routes/content';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as pagesIndex } from '@/routes/pages';
import type { Paginated } from '@/types';

type ContentRow = {
    publication: ArticlePublication;
    id: string;
    title: string;
    slug: string;
    locale: string;
    state: string;
    state_label: string;
    is_live: boolean;
    type: string;
    type_label: string;
    target_query: string | null;
    topic_difficulty: number | null;
    topic_volume: number | null;
    published_at: string | null;
    plan_month: string | null;
    /** Every language this unit exists in. */
    locales: string[];
};

type Props = {
    view: string;
    search: string;
    status_counts: Record<string, number>;
    planning: boolean;
    items: Paginated<ContentRow>;
};

export default function ContentIndex({
    items,
    view,
    search,
    status_counts: counts,
    planning,
}: Props) {
    usePoll(15000, { only: ['items', 'planning', 'status_counts'] });
    const returnTo = index({
        query: {
            view: view === 'all' ? undefined : view,
            search: search || undefined,
            page: items.current_page > 1 ? items.current_page : undefined,
        },
    }).url;
    const articleUrl = (itemId: string) =>
        show(itemId, { query: { return_to: returnTo } }).url;
    const scheduleUrl = (itemId: string) => `${articleUrl(itemId)}#publication`;

    return (
        <>
            <Head title="Content" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Content"
                    context={`${items.total} ${items.total === 1 ? 'article' : 'articles'}`}
                    title="Content for your customers"
                    description="Everything Avyo is preparing and publishing. Open an article to read it, request changes, or choose when it goes live."
                    actions={
                        <>
                            <ContentActions planning={planning} />
                            <PlanViews active="list" />
                            <Button asChild variant="outline">
                                <Link href={approvalsIndex()}>
                                    Review queue
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <nav
                        className="flex flex-wrap gap-2"
                        aria-label="Content status"
                    >
                        {[
                            ['all', 'All content'],
                            ['writing', 'Being written'],
                            ['review', 'Needs attention'],
                            ['scheduled', 'Scheduled'],
                            ['published', 'Published'],
                        ].map(([key, label]) => (
                            <Button
                                key={key}
                                asChild
                                variant={view === key ? 'default' : 'outline'}
                                size="sm"
                            >
                                <Link
                                    href={index({
                                        query: {
                                            view: key,
                                            search: search || undefined,
                                        },
                                    })}
                                    aria-current={
                                        view === key ? 'page' : undefined
                                    }
                                >
                                    {label} ({counts[key] ?? 0})
                                </Link>
                            </Button>
                        ))}
                    </nav>
                    <Form
                        action={index().url}
                        method="get"
                        className="flex min-w-0 gap-2 sm:w-80"
                    >
                        <input type="hidden" name="view" value={view} />
                        <label
                            className="sr-only"
                            htmlFor="content-title-search"
                        >
                            Search article titles
                        </label>
                        <Input
                            key={search}
                            id="content-title-search"
                            name="search"
                            type="search"
                            defaultValue={search}
                            maxLength={120}
                            placeholder="Search article titles"
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </div>
                {items.data.length === 0 ? (
                    <EmptyContent
                        filtered={view !== 'all' || search !== ''}
                        search={search}
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 sm:hidden">
                            {items.data.map((item) => (
                                <MobileContentCard
                                    key={item.id}
                                    item={item}
                                    articleUrl={articleUrl(item.id)}
                                    scheduleUrl={scheduleUrl(item.id)}
                                />
                            ))}
                        </div>

                        <Card
                            className={`${workspacePanelClass} hidden max-w-full overflow-hidden p-0 sm:block`}
                        >
                            <Table className="min-w-[760px] table-fixed">
                                <TableHeader className="bg-muted/20 text-xs tracking-wide uppercase">
                                    <TableRow>
                                        <TableHead className="w-[38%] whitespace-normal">
                                            Title
                                        </TableHead>
                                        <TableHead className="whitespace-normal">
                                            Type
                                        </TableHead>
                                        <TableHead className="whitespace-normal">
                                            Languages
                                        </TableHead>
                                        <TableHead className="whitespace-normal">
                                            Publication timing
                                        </TableHead>
                                        <TableHead className="whitespace-normal">
                                            Editorial status
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.map((item) => (
                                        <TableRow
                                            key={item.id}
                                            className="cursor-pointer hover:bg-violet-500/[0.035]"
                                        >
                                            <TableCell className="max-w-md whitespace-normal">
                                                {/* The link is on the title rather
                                                than the row: a whole row that
                                                navigates cannot be opened in a
                                                new tab, copied, or reached by
                                                keyboard. */}
                                                <Link
                                                    href={articleUrl(item.id)}
                                                    className="font-medium break-words hover:underline"
                                                >
                                                    {item.title}
                                                </Link>
                                                {item.target_query !== null && (
                                                    <span className="mt-1 block text-xs break-words text-muted-foreground">
                                                        {item.target_query}
                                                        {item.topic_volume !==
                                                            null && (
                                                            <>
                                                                {' · '}
                                                                {item.topic_volume.toLocaleString()}
                                                                /mo
                                                            </>
                                                        )}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {item.type_label}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1 whitespace-normal">
                                                    {item.locales.map(
                                                        (locale) => (
                                                            <Badge
                                                                key={locale}
                                                                variant="outline"
                                                            >
                                                                {locale}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="whitespace-normal text-muted-foreground">
                                                <p>{publicationTiming(item)}</p>
                                                {item.publication.status !==
                                                    'published' && (
                                                    <Link
                                                        href={scheduleUrl(
                                                            item.id,
                                                        )}
                                                        className="mt-1 inline-block text-xs font-medium underline underline-offset-4"
                                                    >
                                                        {item.publication
                                                            .schedule
                                                            ? 'Change schedule'
                                                            : 'Schedule article'}
                                                    </Link>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        item.is_live
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {item.state_label}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </>
                )}

                <Pagination page={items} />
                <details className={`${workspacePanelClass} p-5`}>
                    <summary className="cursor-pointer text-sm font-medium">
                        Improve existing website content
                    </summary>
                    <p className="mt-3 text-sm leading-6 text-muted-foreground">
                        Review changes to service pages, maintain accurate
                        business information, or check a publishing issue.
                    </p>
                    <div className="mt-4 flex flex-wrap gap-4 text-sm">
                        <Link href={pagesIndex()} className="underline">
                            Website pages
                        </Link>
                        <Link href="/plan" className="underline">
                            Suggested improvements
                        </Link>
                        <Link href="/business-facts" className="underline">
                            Business information
                        </Link>
                        <Link href={deliveriesIndex()} className="underline">
                            Publishing history
                        </Link>
                    </div>
                </details>
            </WorkspacePage>
        </>
    );
}

function MobileContentCard({
    item,
    articleUrl,
    scheduleUrl,
}: {
    item: ContentRow;
    articleUrl: string;
    scheduleUrl: string;
}) {
    return (
        <article className="group flex min-w-0 flex-col gap-4 rounded-[1.25rem] border bg-card/80 p-4 shadow-sm backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/35 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
            <div className="flex min-w-0 items-start justify-between gap-3">
                <div className="min-w-0">
                    <Link
                        href={articleUrl}
                        className="leading-snug font-medium break-words group-hover:underline"
                    >
                        {item.title}
                    </Link>
                    {item.target_query !== null && (
                        <p className="mt-1 text-xs break-words text-muted-foreground">
                            {item.target_query}
                            {item.topic_volume !== null && (
                                <>
                                    {' · '}
                                    {item.topic_volume.toLocaleString()}/mo
                                </>
                            )}
                        </p>
                    )}
                </div>
                <Badge
                    variant={item.is_live ? 'default' : 'secondary'}
                    className="rounded-full"
                >
                    {item.state_label}
                </Badge>
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t pt-3 text-xs text-muted-foreground">
                <Badge variant="outline" className="rounded-full">
                    {item.type_label}
                </Badge>
                {item.locales.map((locale) => (
                    <Badge
                        key={locale}
                        variant="outline"
                        className="rounded-full"
                    >
                        {locale}
                    </Badge>
                ))}
                <span className="ml-auto">{publicationTiming(item)}</span>
            </div>
            {item.publication.status !== 'published' && (
                <Link
                    href={scheduleUrl}
                    className="self-start text-sm font-medium underline underline-offset-4"
                >
                    {item.publication.schedule
                        ? 'Change schedule'
                        : 'Schedule article'}
                </Link>
            )}
        </article>
    );
}

function publicationTiming(item: ContentRow): string {
    if (item.publication.schedule) {
        return `${item.publication.schedule.local_date} · ${item.publication.schedule.local_time} · ${publicationLabels[item.publication.status]}`;
    }

    if (item.publication.status === 'published') {
        return item.published_at === null
            ? 'Published'
            : `Published ${item.published_at.slice(0, 10)}`;
    }

    return 'Not scheduled';
}

function EmptyContent({
    filtered,
    search,
}: {
    filtered: boolean;
    search: string;
}) {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <FileText
                    className="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <CardTitle>
                    {filtered
                        ? search !== ''
                            ? 'No article titles match your search'
                            : 'No content in this view'
                        : 'Your first useful article starts here'}
                </CardTitle>
                <CardDescription>
                    {filtered
                        ? search !== ''
                            ? `No titles match “${search}” in this status. Clear the filters to see all content.`
                            : 'Choose another status, or clear the filters to see all content.'
                        : 'Choose “Plan my content” for topics based on your business, or create an article about a question your customers ask.'}
                </CardDescription>
                {filtered && (
                    <Button asChild variant="outline" className="mt-4">
                        <Link href={index()}>Clear filters</Link>
                    </Button>
                )}
            </CardHeader>
        </Card>
    );
}

ContentIndex.layout = {
    breadcrumbs: [{ title: 'Content', href: index() }],
};
