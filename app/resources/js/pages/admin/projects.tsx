import { Head, Link, router } from '@inertiajs/react';
import { AdminTabs } from '@/components/admin-tabs';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
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
import { useDebouncedSearch } from '@/hooks/use-debounced-search';
import { projects as projectsRoute } from '@/routes/admin';
import type { Paginated } from '@/types';

type Row = {
    id: string;
    name: string;
    slug: string;
    website_url: string | null;
    status: string;
    plan: string | null;
    billing_status: string | null;
    trial_ends_at: string | null;
    price_cents: number;
    currency: string | null;
    cost_micros: number;
    cost_complete: boolean;
};

type Props = {
    q: string;
    cost_currency: string;
    projects: Paginated<Row>;
};

export default function AdminProjects({ q, cost_currency, projects }: Props) {
    const money = (cents: number, currency: string) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 2,
            currencyDisplay: 'code',
        }).format(cents / 100);

    const [query, setQuery] = useDebouncedSearch(q, (value) =>
        router.get(
            projectsRoute().url,
            { q: value },
            { preserveState: true, preserveScroll: true, replace: true },
        ),
    );

    return (
        <>
            <Head title="Projects" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context={`${projects.total} in total`}
                    title="Projects"
                    description="Every tenant, what it is on, and what it has cost this month."
                />

                <AdminTabs current="projects" />

                <Input
                    type="search"
                    placeholder="Search by name, slug or website"
                    aria-label="Search projects"
                    className="max-w-md"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                />

                <Card
                    className={`${workspacePanelClass} gap-0 overflow-hidden p-0`}
                >
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Project</TableHead>
                                        <TableHead>Plan</TableHead>
                                        <TableHead>Engine</TableHead>
                                        <TableHead className="text-right">
                                            Plan price
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Recorded usage
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {projects.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="h-28 text-center text-muted-foreground"
                                            >
                                                {/*
                                                 * An empty page is not an empty table. Paging past
                                                 * the last page — after somebody else removed rows,
                                                 * or on a stale back button — also arrives here, and
                                                 * `Pagination` renders nothing once there is only one
                                                 * page left, so saying "none yet" would strand a
                                                 * reader on a deployment that is full.
                                                 */}
                                                {projects.total === 0 ? (
                                                    q === '' ? (
                                                        'No projects yet.'
                                                    ) : (
                                                        'No project matches that search.'
                                                    )
                                                ) : (
                                                    <>
                                                        Nothing on this page.{' '}
                                                        <Link
                                                            href={projectsRoute(
                                                                {
                                                                    query: {
                                                                        q,
                                                                    },
                                                                },
                                                            )}
                                                            className="underline underline-offset-4"
                                                        >
                                                            Back to the first
                                                            page
                                                        </Link>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {projects.data.map((project) => (
                                        <TableRow key={project.id}>
                                            <TableCell>
                                                <Link
                                                    href={`${projectsRoute().url}/${project.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {project.name}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {project.website_url ??
                                                        project.slug}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <Badge variant="outline">
                                                        {project.plan ??
                                                            'no plan'}
                                                    </Badge>
                                                    {project.billing_status && (
                                                        <Badge
                                                            variant={
                                                                project.billing_status ===
                                                                'active'
                                                                    ? 'secondary'
                                                                    : 'destructive'
                                                            }
                                                        >
                                                            {project.billing_status.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground capitalize">
                                                {project.status}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {project.currency
                                                    ? money(
                                                          project.price_cents,
                                                          project.currency,
                                                      )
                                                    : 'No plan'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(
                                                    project.cost_micros /
                                                        10_000,
                                                    cost_currency,
                                                )}
                                                {!project.cost_complete && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Known subtotal; some
                                                        charges unknown
                                                    </p>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Pagination page={projects} />
            </WorkspacePage>
        </>
    );
}

AdminProjects.layout = {
    breadcrumbs: [{ title: 'Projects', href: projectsRoute() }],
};
