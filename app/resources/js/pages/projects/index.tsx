import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, FolderKanban, Globe2, Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { show as startOnboarding } from '@/routes/onboarding';
import { edit as editProject, index } from '@/routes/projects';
import type { ProjectStatus } from '@/types';

type ProjectRow = {
    id: string;
    name: string;
    slug: string;
    status: ProjectStatus;
    timezone: string;
    default_locale: string;
    locales: string[];
    created_at: string | null;
    role: 'owner' | 'operator';
};

type Props = {
    projects: ProjectRow[];
};

export default function ProjectsIndex({ projects }: Props) {
    return (
        <>
            <Head title="Projects" />

            <WorkspacePage>
                <WorkspaceHeader
                    eyebrow="Workspace"
                    context={`${projects.length} ${projects.length === 1 ? 'project' : 'projects'}`}
                    title="Projects"
                    description="Each project is a private content workspace with its own brand, languages, publishing calendar, and integrations."
                    actions={
                        <Button className="rounded-full" asChild>
                            <Link href={startOnboarding()}>
                                <Plus className="size-4" aria-hidden="true" />
                                New project
                            </Link>
                        </Button>
                    }
                />

                {projects.length === 0 ? (
                    <EmptyProjects />
                ) : (
                    <div className="grid min-w-0 gap-4 lg:grid-cols-2">
                        {projects.map((project) => (
                            <ProjectCard key={project.id} project={project} />
                        ))}
                    </div>
                )}
            </WorkspacePage>
        </>
    );
}

function ProjectCard({ project }: { project: ProjectRow }) {
    return (
        <Card
            className={`${workspacePanelClass} min-w-0 gap-5 overflow-hidden py-0 transition-all hover:-translate-y-0.5 hover:border-violet-500/25 hover:shadow-[0_20px_52px_rgba(15,23,42,0.08)]`}
        >
            <CardHeader className="flex-row items-start justify-between gap-4 border-b px-5 py-5 sm:px-6">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-violet-500/10 text-sm font-semibold text-violet-600 dark:text-violet-300">
                        {project.name.slice(0, 2).toUpperCase()}
                    </span>
                    <div className="min-w-0">
                        <h2 className="truncate text-lg font-semibold tracking-tight">
                            {project.name}
                        </h2>
                        <p className="truncate text-xs text-muted-foreground">
                            {project.slug}
                        </p>
                    </div>
                </div>
                <Badge
                    variant={
                        project.status === 'active' ? 'default' : 'secondary'
                    }
                    className="rounded-full capitalize"
                >
                    {project.status}
                </Badge>
            </CardHeader>

            <CardContent className="flex flex-col gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
                <dl className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Time zone
                        </dt>
                        <dd className="mt-1 font-medium">{project.timezone}</dd>
                    </div>
                    <div>
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Access
                        </dt>
                        <dd className="mt-1 font-medium capitalize">
                            {project.role}
                        </dd>
                    </div>
                </dl>

                <div>
                    <p className="mb-2 flex items-center gap-1.5 text-xs tracking-wide text-muted-foreground uppercase">
                        <Globe2 className="size-3.5" aria-hidden="true" />
                        Publishing languages
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                        {project.locales.map((locale) => (
                            <Badge
                                key={locale}
                                variant="outline"
                                className={`rounded-full ${
                                    locale === project.default_locale
                                        ? 'border-violet-500/40 bg-violet-500/5 text-violet-700 dark:text-violet-300'
                                        : ''
                                }`}
                            >
                                {locale}
                                {locale === project.default_locale &&
                                    ' · default'}
                            </Badge>
                        ))}
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 border-t pt-4">
                    <span className="text-xs text-muted-foreground">
                        Created {formatProjectDate(project.created_at)}
                    </span>
                    {project.role === 'owner' && (
                        <Button
                            variant="outline"
                            className="rounded-full bg-background/70"
                            asChild
                        >
                            <Link href={editProject(project.id)}>
                                Settings
                                <ArrowUpRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function formatProjectDate(value: string | null) {
    if (value === null) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function EmptyProjects() {
    return (
        <Card className={`${workspacePanelClass} py-12`}>
            <CardHeader className="items-center text-center">
                <span className="flex size-12 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
                    <FolderKanban className="size-6" aria-hidden="true" />
                </span>
                <h2 className="text-lg font-semibold">No projects yet</h2>
                <p className="max-w-md text-sm text-muted-foreground">
                    Start with a website. The setup flow will propose the brand,
                    audience, competitors, and first publishing cadence.
                </p>
                <Button className="mt-2 rounded-full" asChild>
                    <Link href={startOnboarding()}>
                        <Plus className="size-4" aria-hidden="true" />
                        Set up a project
                    </Link>
                </Button>
            </CardHeader>
        </Card>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
