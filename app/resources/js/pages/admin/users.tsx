import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminTabs } from '@/components/admin-tabs';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    anderro_configured: boolean;
    users: Paginated<Row>;
};

/**
 * Nothing here changes an account.
 *
 * Everything an administrator needs to *do* is done to a project. The one
 * user-shaped action that would be useful — signing in as somebody to see what
 * they are seeing — is the only feature here that can act as a customer, and it
 * should arrive with its own audit trail and its own argument rather than as a
 * line item in a billing change. The test signup sent to Anderro touches
 * nothing of ours.
 */
export default function AdminUsers({ q, anderro_configured, users }: Props) {
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

                <AdminTabs current="users" />

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
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
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
                                                {users.total === 0 ? (
                                                    q === '' ? (
                                                        'No accounts yet.'
                                                    ) : (
                                                        'No account matches that search.'
                                                    )
                                                ) : (
                                                    <>
                                                        Nothing on this page.{' '}
                                                        <Link
                                                            href={usersRoute({
                                                                query: { q },
                                                            })}
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
                                            <TableCell className="text-right">
                                                <TestSignup
                                                    user={user}
                                                    configured={
                                                        anderro_configured
                                                    }
                                                />
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

/**
 * Send Anderro a signup for this account, to check the integration end to end.
 *
 * Sent at once and answered in a toast. Leaving the visitor id empty uses the
 * one recorded for a referred account, or a new one Anderro credits to nobody;
 * pasting the `_anderro_vid` cookie from a browser that clicked a partner link
 * tests the crediting as well.
 */
function TestSignup({ user, configured }: { user: Row; configured: boolean }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!configured}
                    title={
                        configured
                            ? undefined
                            : 'Anderro is not configured on this deployment'
                    }
                >
                    Test signup
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    action={`${usersRoute().url}/${user.id}/anderro-signup`}
                    method="post"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Send a test signup to Anderro?
                                </DialogTitle>
                                <DialogDescription>
                                    Anderro is told that {user.email} signed up.
                                    It's sent now, whether or not a partner
                                    referred this account or it allowed
                                    marketing cookies, and it's written to the
                                    admin log.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-2 py-4">
                                <Label htmlFor={`visitor-${user.id}`}>
                                    Visitor id (optional)
                                </Label>
                                <Input
                                    id={`visitor-${user.id}`}
                                    name="visitor_id"
                                    autoComplete="off"
                                    placeholder="_anderro_vid from a browser that clicked a partner link"
                                    aria-invalid={
                                        errors.visitor_id ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.visitor_id
                                            ? `visitor-${user.id}-error`
                                            : undefined
                                    }
                                />
                                <InputError
                                    id={`visitor-${user.id}-error`}
                                    message={errors.visitor_id}
                                />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Send signup
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

AdminUsers.layout = {
    breadcrumbs: [{ title: 'Accounts', href: usersRoute() }],
};
