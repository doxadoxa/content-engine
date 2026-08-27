import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BookMarked,
    CalendarDays,
    Gauge,
    House,
    Inbox,
    LayoutList,
    MessagesSquare,
    Radio,
    Send,
    Sparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
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
import { index as chatIndex } from '@/routes/assistant';
import { index as auditIndex } from '@/routes/audit';
import { edit as briefEdit } from '@/routes/brief';
import { index as calendarIndex } from '@/routes/calendar';
import { index as channelsIndex } from '@/routes/channels';
import { index as contentIndex } from '@/routes/content';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { index as engageIndex } from '@/routes/engage';
import { index as feedbackIndex } from '@/routes/feedback';
import { index as homeIndex } from '@/routes/home';
import {
    create as socialCreate,
    index as socialIndex,
    plan as socialPlan,
} from '@/routes/social';
import { index as visibilityIndex } from '@/routes/visibility';

/**
 * The navigation column.
 *
 * Every link here goes to a page that does something today — a column of links
 * to pages that do not exist yet teaches an operator to distrust the menu.
 *
 * Grouped by **product line**, because this engine does two jobs that share
 * almost nothing. It writes articles for search and posts for social, and
 * `ContentItem::scopeRoots()` excludes social posts outright — so Content plan,
 * Feedback, Prompt analysis and Site audit are article screens however general
 * their names sound. A single flat "Workflow" list put them beside the social
 * screens with nothing saying which was which, and no amount of ordering fixes
 * a label that does not say what it covers.
 *
 * Two screens serve both halves and sit in neither group: the approvals queue
 * and the delivery log. Cross-project and owner-only administration lives in the
 * project switcher instead of competing with the daily workflow.
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
                            {/* One row, and it took three tries to get here.
                                This group was Home, Today and Dashboard — and
                                to anybody who had not built them, all three
                                read as "dashboard". The defence in the code was
                                that they answered different questions: what
                                should I do, what happened today, what is the
                                engine doing. They did not. They asked one
                                question at three levels of anxiety, and the
                                proof was that all three counted the same drafts
                                with different queries and different words, so
                                Home said "38 social drafts" where the dashboard
                                said "52 waiting for you" about the same
                                morning.

                                A row you do not trust to differ from its
                                neighbours is a row you stop clicking, which is
                                how the product ended up with three landing
                                screens and no landing screen. */}
                            <NavLink
                                href={homeIndex().url}
                                icon={House}
                                label="Home"
                                current={url}
                                also={[dashboard().url]}
                            />
                            {/* Its own row, and genuinely a different question
                                from the one above: Home is what the project is
                                doing, this is what you have been discussing.
                                Conversations have names and addresses, so a
                                list of them is a place rather than a panel. */}
                            <NavLink
                                href={chatIndex().url}
                                icon={MessagesSquare}
                                label="Chats"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                {/*
                    Grouped by product line, and that is the whole point of the
                    labels. This engine does two different jobs — it writes
                    articles for search and it writes posts for social — and
                    they share almost nothing: `ContentItem::scopeRoots()`
                    excludes social posts outright, so "Content plan", Feedback,
                    Prompt analysis and Site audit are *articles only* however
                    general their names sound. One flat "Workflow" list put them
                    beside the social screens with nothing saying which was
                    which, and the answer was not discoverable from the label.
                */}
                <SidebarGroup className="px-3 py-2">
                    <SidebarGroupLabel className="text-[10px] tracking-[0.16em] text-sidebar-foreground/60 uppercase">
                        Social
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {/* One entry, three tabs. This was two rows —
                                "Social" and "Studio" — with nothing in the
                                column explaining which to open, and the same
                                month's work behind both. The monthly assistant
                                is a view of this surface, not a place. */}
                            <NavLink
                                href={socialIndex().url}
                                icon={LayoutList}
                                label="Social"
                                current={url}
                                also={[socialCreate().url, socialPlan().url]}
                            />
                            {/* The same duty at a different clock speed: §4.2
                                measures a reply in minutes where a draft is
                                measured in days. */}
                            {social && (
                                <NavLink
                                    href={engageIndex().url}
                                    icon={MessagesSquare}
                                    label="Conversations"
                                    current={url}
                                />
                            )}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup className="px-3 py-2">
                    <SidebarGroupLabel className="text-[10px] tracking-[0.16em] text-sidebar-foreground/60 uppercase">
                        Articles
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
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
                            {/* Last, and deliberately: the other two are about
                                what the writing achieved, and this is about
                                whether the site it lands on can be read at all.
                                An operator reaches for it when one of the two
                                above is disappointing. */}
                            <NavLink
                                href={auditIndex().url}
                                icon={Gauge}
                                label="Site audit"
                                current={url}
                            />
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup className="px-3 py-2">
                    <SidebarGroupLabel className="text-[10px] tracking-[0.16em] text-sidebar-foreground/60 uppercase">
                        Review
                    </SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {/* The two screens that genuinely serve both
                                halves, which is why they are neither under
                                Social nor under Articles. §7 makes the queue
                                the screen an operator opens every morning and
                                it stays one queue; what changed is where a row
                                goes — a post opens the composer, an article the
                                unit card. */}
                            <NavLink
                                href={approvalsIndex().url}
                                icon={Inbox}
                                label="Approvals"
                                current={url}
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

                <SidebarGroup className="px-3 py-2">
                    <SidebarGroupLabel className="text-[10px] tracking-[0.16em] text-sidebar-foreground/60 uppercase">
                        Project
                    </SidebarGroupLabel>
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
