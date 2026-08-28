import { Head, Link, router } from '@inertiajs/react';
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
    cost_micros: number;
};

type Props = {
    q: string;
    currency: string;
    projects: Paginated<Row>;
};

export default function AdminProjects({ q, currency, projects }: Props) {
    const money = (cents: number) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 0,
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
                                            Pays
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Costs
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
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
                                                {money(project.price_cents)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(
                                                    project.cost_micros /
                                                        10_000,
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
