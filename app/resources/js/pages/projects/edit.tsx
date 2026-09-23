import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { GoogleConnection } from '@/components/google-connection';
import type { GooglePanel } from '@/components/google-connection';
import InputError from '@/components/input-error';
import { ProjectForm } from '@/components/project-form';
import type { ProjectFormValues } from '@/components/project-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    WorkspaceHeader,
    WorkspacePage,
    workspacePanelClass,
} from '@/components/workspace-page';
import { archive, index, update } from '@/routes/projects';

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

                {/* The route is owner-only, so everybody here may archive. */}
                <ArchiveProject id={project.id} name={project.name} />
            </WorkspacePage>
        </>
    );
}

/**
 * The way out of a project somebody no longer wants.
 *
 * Behind a typed name rather than a second click: it cancels
 * the subscription as well as the work, and nothing on this side brings it
 * back.
 */
function ArchiveProject({ id, name }: { id: string; name: string }) {
    const [open, setOpen] = useState(false);
    const [typed, setTyped] = useState('');

    return (
        <Card className={`${workspacePanelClass} border-destructive/40`}>
            <CardHeader>
                <CardTitle className="text-base">
                    Archive this project
                </CardTitle>
                <CardDescription>
                    Avyo stops working on it, the subscription is canceled
                    straight away, and it disappears from your projects. You
                    can't undo this yourself.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Dialog
                    open={open}
                    onOpenChange={(next) => {
                        setOpen(next);
                        setTyped('');
                    }}
                >
                    <DialogTrigger asChild>
                        <Button variant="destructive" className="rounded-full">
                            Archive project
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="rounded-[1.5rem]">
                        <Form {...archive.form(id)}>
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>
                                            Archive {name}?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Scheduled work stops now. Any paid
                                            plan ends now; the rest of the
                                            current period isn't refunded.
                                            What's already published stays on
                                            your site.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <div className="grid gap-2 py-4">
                                        <Label htmlFor="archive-confirmation">
                                            Type <strong>{name}</strong> to
                                            confirm
                                        </Label>
                                        <Input
                                            id="archive-confirmation"
                                            name="confirmation"
                                            autoComplete="off"
                                            value={typed}
                                            onChange={(event) =>
                                                setTyped(event.target.value)
                                            }
                                            aria-invalid={
                                                errors.confirmation
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                errors.confirmation
                                                    ? 'archive-confirmation-error'
                                                    : undefined
                                            }
                                        />
                                        <InputError
                                            id="archive-confirmation-error"
                                            message={errors.confirmation}
                                        />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setOpen(false)}
                                        >
                                            Keep project
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={
                                                processing || typed !== name
                                            }
                                        >
                                            Archive project
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}

EditProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: index() }],
};
