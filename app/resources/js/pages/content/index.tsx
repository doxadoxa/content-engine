import { Head, Link } from '@inertiajs/react';
import { FileText, GitBranch } from 'lucide-react';
import { Pagination } from '@/components/pagination';
import { PlanViews } from '@/components/plan-views';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { index, show } from '@/routes/content';
import type { Paginated } from '@/types';

type ContentRow = {
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
    /** How many social posts hang off it. */
    derivatives: number;
};

type Props = {
    items: Paginated<ContentRow>;
};

export default function ContentIndex({ items }: Props) {
    return (
        <>
            <Head title="Content plan — List" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Content plan"
                    context={`${items.total} ${items.total === 1 ? 'article' : 'articles'}`}
                    title="All articles"
                    description="Each row is one article. Its language versions and related social posts stay grouped together."
                    actions={<PlanViews active="list" />}
                />

                {items.data.length === 0 ? (
                    <EmptyContent />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 sm:hidden">
                            {items.data.map((item) => (
                                <MobileContentCard key={item.id} item={item} />
                            ))}
                        </div>

                        <Card
                            className={`${workspacePanelClass} hidden max-w-full overflow-hidden p-0 sm:block`}
                        >
                            <Table className="min-w-[760px]">
                                <TableHeader className="bg-muted/20 text-xs tracking-wide uppercase">
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Languages</TableHead>
                                        <TableHead>Related posts</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>State</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.data.map((item) => (
                                        <TableRow
                                            key={item.id}
                                            className="cursor-pointer hover:bg-violet-500/[0.035]"
                                        >
                                            <TableCell className="max-w-sm">
                                                {/* The link is on the title rather
                                                than the row: a whole row that
                                                navigates cannot be opened in a
                                                new tab, copied, or reached by
                                                keyboard. */}
                                                <Link
                                                    href={show(item.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {item.title}
                                                </Link>
                                                {item.target_query !== null && (
                                                    <span className="block truncate text-xs text-muted-foreground">
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
                                                <div className="flex flex-wrap gap-1">
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
                                            <TableCell className="text-muted-foreground">
                                                {item.derivatives === 0 ? (
                                                    '—'
                                                ) : (
                                                    <span className="flex items-center gap-1.5">
                                                        <GitBranch
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {item.derivatives}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {item.plan_month ?? '—'}
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
            </WorkspacePage>
        </>
    );
}

function MobileContentCard({ item }: { item: ContentRow }) {
    return (
        <Link
            href={show(item.id)}
            className="group flex min-w-0 flex-col gap-4 rounded-[1.25rem] border bg-card/80 p-4 shadow-sm backdrop-blur-sm transition-all hover:-translate-y-0.5 hover:border-violet-500/35 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div className="flex min-w-0 items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="leading-snug font-medium break-words group-hover:underline">
                        {item.title}
                    </p>
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
                {item.derivatives > 0 && (
                    <span className="flex items-center gap-1">
                        <GitBranch className="size-3.5" aria-hidden="true" />
                        {item.derivatives}{' '}
                        {item.derivatives === 1
                            ? 'related post'
                            : 'related posts'}
                    </span>
                )}
                {item.plan_month !== null && (
                    <span className="ml-auto">{item.plan_month}</span>
                )}
            </div>
        </Link>
    );
}

function EmptyContent() {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <FileText
                    className="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <CardTitle>No articles yet</CardTitle>
                <CardDescription>
                    Plan a month to create article ideas, then use Calendar to
                    schedule them and Approvals to review drafts.
                </CardDescription>
            </CardHeader>
        </Card>
    );
}

ContentIndex.layout = {
    breadcrumbs: [{ title: 'Content plan', href: index() }],
};
