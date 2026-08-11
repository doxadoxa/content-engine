import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

/**
 * One bar across the top of whatever page you are on: where you are on the
 * left, who you are on the right.
 */
export function AppTopBar({ breadcrumbs = [] }: Props) {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    return (
        <header className="flex h-[4.5rem] shrink-0 items-center gap-2 border-b border-border/80 bg-background/88 px-4 backdrop-blur-xl sm:px-6">
            <SidebarTrigger className="-ml-1 border border-border/70 bg-card/70 shadow-sm" />

            {breadcrumbs.length > 0 && (
                <>
                    <Separator
                        orientation="vertical"
                        className="mr-2 data-[orientation=vertical]:h-4"
                    />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </>
            )}

            <div className="ml-auto flex items-center gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            className="size-10 border border-border/70 bg-card/70 p-1 shadow-sm"
                            aria-label="Account menu"
                        >
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarFallback className="rounded-full bg-[#f2d9d0] font-serif text-sm font-semibold text-[#17352f] italic dark:bg-[#f3cf6a]">
                                    {getInitials(auth.user?.name ?? '')}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-56" align="end">
                        {auth.user && <UserMenuContent user={auth.user} />}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
