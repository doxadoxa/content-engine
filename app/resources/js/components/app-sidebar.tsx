import { Link, usePage } from '@inertiajs/react';
import { Search, CalendarDays, FileText, House, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { ProjectSwitcher } from '@/components/project-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { openPreferences } from '@/lib/consent';
import { dashboard } from '@/routes';
import { index as factsIndex } from '@/routes/business-facts';
import { index as calendarIndex } from '@/routes/calendar';
import { index as contentIndex } from '@/routes/content';
import { index as feedbackIndex } from '@/routes/feedback';
import { index as homeIndex } from '@/routes/home';
import { cookies, privacy, terms } from '@/routes/legal';
import { index as pagesIndex } from '@/routes/pages';
import { index as performanceIndex } from '@/routes/performance';
import { index as visibilityIndex } from '@/routes/visibility';

/** Everyday publishing and growth reports. Project administration lives in the switcher. */
export function AppSidebar() {
    const { url } = usePage();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-3 border-b border-sidebar-border/70 p-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeIndex()} prefetch>
                                <AppLogoIcon className="size-8 shrink-0" />
                                <span className="text-base font-semibold tracking-[-0.04em]">
                                    Avyo
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <ProjectSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-3 py-2">
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <NavLink
                                href={homeIndex().url}
                                icon={House}
                                label="Dashboard"
                                current={url}
                                also={[dashboard().url, '/purchases']}
                            />
                            <NavLink
                                href={calendarIndex().url}
                                icon={CalendarDays}
                                label="Calendar"
                                current={url}
                            />
                            <NavLink
                                href={contentIndex().url}
                                icon={FileText}
                                label="Content"
                                current={url}
                                also={[
                                    pagesIndex().url,
                                    factsIndex().url,
                                    '/plan',
                                    '/proposals',
                                    '/approvals',
                                ]}
                            />
                            <NavLink
                                href={performanceIndex().url}
                                icon={Search}
                                label="Search performance"
                                current={url}
                                also={[feedbackIndex().url]}
                            />
                            <NavLink
                                href={visibilityIndex().url}
                                icon={Sparkles}
                                label="AI visibility"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 px-2 pb-1 text-[11px] text-sidebar-foreground/60 group-data-[collapsible=icon]:hidden">
                    <Link href={privacy.url()} className="hover:underline">
                        Privacy
                    </Link>
                    <Link href={terms.url()} className="hover:underline">
                        Terms
                    </Link>
                    <Link href={cookies.url()} className="hover:underline">
                        Cookies
                    </Link>
                    <button
                        type="button"
                        onClick={openPreferences}
                        className="hover:underline"
                    >
                        Cookie settings
                    </button>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}

/**
 * One row.
 *
 * `current` is the whole URL of the page being viewed, including its query
 * string — a paginated section is still that section, so the comparison is on
 * the path alone.
 */
function NavLink({
    href,
    icon: Icon,
    label,
    current,
    also = [],
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    current: string;
    /** Other paths this entry owns — a second view of the same section. */
    also?: string[];
}) {
    const path = current.split('?')[0];
    const isActive =
        path === href ||
        path.startsWith(`${href}/`) ||
        also.some((other) => path === other || path.startsWith(`${other}/`));

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={isActive}
                tooltip={label}
                className="relative rounded-xl data-[active=true]:before:absolute data-[active=true]:before:top-1/2 data-[active=true]:before:left-0 data-[active=true]:before:h-4 data-[active=true]:before:w-0.5 data-[active=true]:before:-translate-y-1/2 data-[active=true]:before:rounded-full data-[active=true]:before:bg-[#f3cf6a]"
            >
                {/* `isActive` styles the row and nothing more, so without
                    `aria-current` the section you are standing in is marked
                    for people who can see the highlight and nobody else. */}
                <Link
                    href={href}
                    prefetch
                    aria-current={isActive ? 'page' : undefined}
                >
                    <Icon
                        aria-hidden="true"
                        className={isActive ? 'text-[#f3cf6a]' : undefined}
                    />
                    <span>{label}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}
