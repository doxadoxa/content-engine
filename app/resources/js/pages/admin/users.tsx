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
import { projects as projectsRoute, users as usersRoute } from '@/routes/admin';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    verified: boolean;
    created_at: string | null;
    projects: { id: string; name: string; slug: string; role: string | null }[];
};

type Props = {
    q: string;
    users: Paginated<Row>;
};

/**
 * Read-only, and deliberately so.
 *
 * Everything an administrator needs to *do* is done to a project. The one
 * user-shaped action that would be useful — signing in as somebody to see what
 * they are seeing — is the only feature here that can act as a customer, and it
 * should arrive with its own audit trail and its own argument rather than as a
 * line item in a billing change.
 */
export default function AdminUsers({ q, users }: Props) {
    const [query, setQuery] = useDebouncedSearch(q, (value) =>
        router.get(
            usersRoute().url,
            { q: value },
            { preserveState: true, preserveScroll: true, replace: true },
        ),
    );

    return (
        <>
            <Head title="Accounts" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Administration"
                    context={`${users.total} in total`}
                    title="Accounts"
                    description="Who has signed up, and which projects they can reach."
                />

                <Input
                    type="search"
                    placeholder="Search by name or email"
                    aria-label="Search accounts"
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
                                        <TableHead>Account</TableHead>
                                        <TableHead>Projects</TableHead>
                                        <TableHead>Joined</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {user.name}
                                                    </span>
                                                    {user.is_admin && (
                                                        <Badge variant="secondary">
                                                            admin
                                                        </Badge>
                                                    )}
                                                    {!user.verified && (
                                                        <Badge variant="outline">
                                                            unverified
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {user.email}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {user.projects.length === 0 ? (
                                                    <span className="text-sm text-muted-foreground">
                                                        none yet
                                                    </span>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {user.projects.map(
                                                            (project) => (
                                                                <Link
                                                                    key={
                                                                        project.id
                                                                    }
                                                                    href={`${projectsRoute().url}/${project.id}`}
                                                                    className="text-sm hover:underline"
                                                                >
                                                                    {
                                                                        project.name
                                                                    }
                                                                    <span className="text-muted-foreground">
                                                                        {' '}
                                                                        ·{' '}
                                                                        {project.role ??
                                                                            'member'}
                                                                    </span>
                                                                </Link>
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground tabular-nums">
                                                {user.created_at?.slice(0, 10)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Pagination page={users} />
            </WorkspacePage>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [{ title: 'Accounts', href: usersRoute() }],
};
