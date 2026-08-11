import { Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronsUpDown,
    CircleDollarSign,
    FolderKanban,
    Plus,
    Settings2,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as meteringIndex } from '@/routes/metering';
import { show as startOnboarding } from '@/routes/onboarding';
import { edit as editProject, index as projectsIndex } from '@/routes/projects';
import { switchMethod } from '@/routes/projects';
import type { Auth } from '@/types';

/**
 * Which project you are working in, and how to be in a different one.
 *
 * In the sidebar header rather than the user menu because it is not a setting
 * about you — it decides what every list on every page contains, and an
 * operator who cannot see which project they are in will eventually approve a
 * draft for the wrong brand.
 *
 * Switching is a POST: it changes where the next page's data comes from, and a
 * GET that changes state is one a browser may prefetch.
 */
export function ProjectSwitcher() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const current = auth.project;
    const options = auth.projects ?? [];

    if (!current) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="rounded-xl border border-sidebar-border/70 bg-white/[0.035] data-[state=open]:bg-sidebar-accent"
                            aria-label={`Project: ${current.name}. Switch project`}
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg border border-sidebar-border bg-[#f3cf6a] font-serif text-xs font-semibold text-[#17352f] uppercase italic">
                                {current.name.slice(0, 2)}
                            </div>
                            <div className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-sm font-medium">
                                    {current.name}
                                </span>
                                <span className="truncate text-xs text-sidebar-foreground/55">
                                    {current.default_locale}
                                    {current.status === 'paused' && ' · paused'}
                                </span>
                            </div>
                            <ChevronsUpDown
                                className="ml-auto size-4"
                                aria-hidden="true"
                            />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                        align="start"
                        side="bottom"
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Projects
                        </DropdownMenuLabel>

                        {options.map((project) => (
                            <DropdownMenuItem
                                key={project.id}
                                onSelect={() => {
                                    if (project.id === current.id) {
                                        return;
                                    }

                                    router.post(
                                        switchMethod(project.id).url,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <span className="truncate">{project.name}</span>
                                {project.status === 'paused' && (
                                    <span className="text-xs text-muted-foreground">
                                        paused
                                    </span>
                                )}
                                {project.id === current.id && (
                                    <Check
                                        className="ml-auto size-4"
                                        aria-hidden="true"
                                    />
                                )}
                            </DropdownMenuItem>
                        ))}

                        <DropdownMenuSeparator />

                        <DropdownMenuItem asChild>
                            <Link href={projectsIndex()}>
                                <FolderKanban
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                All projects
                            </Link>
                        </DropdownMenuItem>

                        <DropdownMenuItem asChild>
                            <Link href={startOnboarding()}>
                                <Plus className="size-4" aria-hidden="true" />
                                New project
                            </Link>
                        </DropdownMenuItem>

                        {current.role === 'owner' && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel className="text-xs text-muted-foreground">
                                    Administration
                                </DropdownMenuLabel>

                                <DropdownMenuItem asChild>
                                    <Link href={editProject(current.id)}>
                                        <Settings2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Project settings
                                    </Link>
                                </DropdownMenuItem>

                                <DropdownMenuItem asChild>
                                    <Link href={meteringIndex()}>
                                        <CircleDollarSign
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Usage &amp; cost
                                    </Link>
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
