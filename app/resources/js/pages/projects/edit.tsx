import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { GoogleConnection } from '@/components/google-connection';
import type { GooglePanel } from '@/components/google-connection';
import { ProjectForm } from '@/components/project-form';
import type { ProjectFormValues } from '@/components/project-form';
import { ThreadsConnection } from '@/components/threads-connection';
import type { ThreadsPanel } from '@/components/threads-connection';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { WorkspaceHeader, WorkspacePage } from '@/components/workspace-page';
import { index, update } from '@/routes/projects';

type Props = {
    project: ProjectFormValues;
    timezones: string[];
    google?: GooglePanel;
    threads?: ThreadsPanel;
};

export default function EditProject({
    project,
    timezones,
    google,
    threads,
}: Props) {
    // No social presence on this deployment, so no panel: the connect flow it
    // offers has no routes behind it. See config/social.php — and note that
    // this is a different answer from the panel's own `unavailable` state,
    // which is for an installation that wants Threads and has no app yet.
    const social = usePage().props.social.enabled;

    return (
        <>
            <Head title={project.name} />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="Project settings"
                    context={project.slug}
                    title={project.name}
                    description="Settings for this project. Changing them affects everything the engine writes for it from here on."
                    actions={
                        <>
                            <Badge
                                variant={
                                    project.status === 'active'
                                        ? 'default'
                                        : 'secondary'
                                }
                                className="h-9 rounded-full px-3 capitalize"
                            >
                                {project.status}
                            </Badge>
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
                                    All projects
                                </Link>
                            </Button>
                        </>
                    }
                />

                <ProjectForm
                    action={{ url: update(project.id).url, method: 'patch' }}
                    timezones={timezones}
                    project={project}
                    submitLabel="Save changes"
                />

                <GoogleConnection projectId={project.id} google={google} />

                {social && (
                    <ThreadsConnection
                        projectId={project.id}
                        threads={threads}
                    />
                )}
            </WorkspacePage>
        </>
    );
}

EditProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
