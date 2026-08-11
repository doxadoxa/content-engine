import { CalendarDays, FolderKanban, Globe2, Inbox, Settings } from 'lucide-react';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
} from 'avyo';

const NAV = [
    { label: 'Today', icon: CalendarDays, active: true },
    { label: 'Approvals', icon: Inbox, badge: '7' },
    { label: 'Projects', icon: FolderKanban },
    { label: 'Visibility', icon: Globe2 },
];

/**
 * Every Sidebar* part reads the context `SidebarProvider` publishes — width,
 * open state, mobile — so the provider is not optional scaffolding here, it is
 * the component. `collapsible="none"` keeps the column in the card instead of
 * fixing it to the viewport edge.
 */
export function Expanded() {
    return (
        <SidebarProvider className="min-h-0 w-auto">
            <Sidebar collapsible="none" className="h-[420px] rounded-xl border">
                <SidebarHeader className="px-4 py-4">
                    <span className="text-base font-semibold">Avyo</span>
                </SidebarHeader>
                <SidebarContent>
                    <SidebarGroup>
                        <SidebarGroupLabel>Workspace</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {NAV.map(({ label, icon: Glyph, active, badge }) => (
                                    <SidebarMenuItem key={label}>
                                        <SidebarMenuButton isActive={active}>
                                            <Glyph />
                                            <span>{label}</span>
                                        </SidebarMenuButton>
                                        {badge ? (
                                            <SidebarMenuBadge>
                                                {badge}
                                            </SidebarMenuBadge>
                                        ) : null}
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </SidebarContent>
                <SidebarFooter>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton>
                                <Settings />
                                <span>Settings</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarFooter>
            </Sidebar>
        </SidebarProvider>
    );
}
