import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { GoogleConnection } from '@/components/google-connection';
import type { GooglePanel } from '@/components/google-connection';
import { ProjectForm } from '@/components/project-form';
import type { ProjectFormValues } from '@/components/project-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { WorkspaceHeader, WorkspacePage } from '@/components/workspace-page';
import { index, update } from '@/routes/projects';

type Props = {
    project: ProjectFormValues;
    timezones: string[];
    google?: GooglePanel;
};

export default function EditProject({ project, timezones, google }: Props) {
    return (
        <>
            <Head title={project.name} />

            <WorkspacePage width="reading">
                <WorkspaceHeader
                    eyebrow="Project settings"
                    context={project.slug}
                    title={project.name}
                    description="Settings for this project. Changes affect content Avyo creates from now on."
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
            </WorkspacePage>
        </>
    );
}

EditProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
