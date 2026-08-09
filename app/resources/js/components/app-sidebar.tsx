import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookMarked,
    CalendarDays,
    Inbox,
    LayoutDashboard,
    MessageSquareText,
    MessagesSquare,
    Radio,
    Send,
    Sparkles,
    Sun,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ProjectSwitcher } from '@/components/project-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as approvalsIndex } from '@/routes/approvals';
import { edit as briefEdit } from '@/routes/brief';
import { index as calendarIndex } from '@/routes/calendar';
import { index as channelsIndex } from '@/routes/channels';
import { index as contentIndex } from '@/routes/content';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as engageIndex } from '@/routes/engage';
import { index as feedbackIndex } from '@/routes/feedback';
import { index as studioIndex } from '@/routes/studio';
import { index as todayIndex } from '@/routes/today';
import { index as visibilityIndex } from '@/routes/visibility';

/**
 * The navigation column.
 *
 * Every link here goes to a page that does something today — a column of links
 * to pages that do not exist yet teaches an operator to distrust the menu.
 *
 * Three compact groups mirror the operator's mental model: make the work,
 * learn from what happened, and shape the current project. Cross-project and
 * owner-only administration lives in the project switcher instead of competing
 * with the daily workflow.
 */
export function AppSidebar() {
    const { url, props } = usePage();
    // Off, this deployment has no Threads app and the two social screens have
    // no routes behind them — see config/social.php. Hidden rather than
    // disabled: a disabled row says "not yet", and there is nothing here or
    // anywhere else in the interface that could turn it on.
    const social = props.social.enabled;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <div className="flex aspect-square size-8 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground">
                                    <CalendarDays
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </div>
                                <span className="text-base font-semibold">
                                    Content Engine
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <ProjectSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {/* Above the dashboard, deliberately. §7 says the
                                project's day is "одна сводка и пять минут", and
                                the first entry in the column is what an
                                operator opens at eight in the morning. It is
                                not the landing route, though: a project still
                                launching has nothing to summarise and the
                                dashboard is the only screen that shows the
                                launch, so the reversible half of the decision
                                is the one that got made. */}
                            {social && (
                                <NavLink
                                    href={todayIndex().url}
                                    icon={Sun}
                                    label="Today"
                                    current={url}
                                />
                            )}
                            <NavLink
                                href={dashboard().url}
                                icon={LayoutDashboard}
                                label="Dashboard"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Workflow</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <NavLink
                                href={studioIndex().url}
                                icon={MessageSquareText}
                                label="Studio"
                                current={url}
                            />
                            {/* First, deliberately: §7 makes the approvals
                                queue the screen an operator opens every
                                morning. */}
                            <NavLink
                                href={approvalsIndex().url}
                                icon={Inbox}
                                label="Approvals"
                                current={url}
                            />
                            {/* Next to it, because it is the same duty at a
                                different clock speed: §4.2 measures a reply in
                                minutes where a draft is measured in days. */}
                            {social && (
                                <NavLink
                                    href={engageIndex().url}
                                    icon={MessagesSquare}
                                    label="Conversations"
                                    current={url}
                                />
                            )}
                            {/* One entry, not two. The calendar and the list
                                are the same units in different shapes, and
                                separate sections made an empty month look like
                                an empty project. */}
                            <NavLink
                                href={calendarIndex().url}
                                icon={CalendarDays}
                                label="Content plan"
                                current={url}
                                also={[contentIndex().url]}
                            />
                            <NavLink
                                href={deliveriesIndex().url}
                                icon={Send}
                                label="Deliveries"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Insights</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <NavLink
                                href={feedbackIndex().url}
                                icon={Activity}
                                label="Feedback"
                                current={url}
                            />
                            <NavLink
                                href={visibilityIndex().url}
                                icon={Sparkles}
                                label="Prompt analysis"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Project</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <NavLink
                                href={briefEdit().url}
                                icon={BookMarked}
                                label="Brand brief"
                                current={url}
                            />
                            <NavLink
                                href={channelsIndex().url}
                                icon={Radio}
                                label="Channels"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>
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
    const isActive = path === href || also.includes(path);

    return (
        <SidebarMenuItem>
            <SidebarMenuButton asChild isActive={isActive} tooltip={label}>
                {/* `isActive` styles the row and nothing more, so without
                    `aria-current` the section you are standing in is marked
                    for people who can see the highlight and nobody else. */}
                <Link
                    href={href}
                    prefetch
                    aria-current={isActive ? 'page' : undefined}
                >
                    <Icon aria-hidden="true" />
                    <span>{label}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}
