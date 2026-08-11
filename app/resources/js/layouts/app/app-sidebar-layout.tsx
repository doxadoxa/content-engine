import { AppTopBar } from '@/components/app-header';
import { AppSidebar } from '@/components/app-sidebar';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import type { AppLayoutProps } from '@/types';

/**
 * The signed-in frame: a navigation column, and a slim bar above whatever page
 * you are on.
 *
 * The provider keeps the column's open state in a cookie of its own, which is
 * why it lives here rather than in the shell — painting the page and knowing
 * where the page can go are different jobs.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs,
}: AppLayoutProps) {
    return (
        <SidebarProvider defaultOpen={wasOpen()} className="product-shell">
            <a
                href="#main-content"
                onClick={() => document.getElementById('main-content')?.focus()}
                className="fixed top-3 left-3 z-100 -translate-y-20 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground shadow focus:translate-y-0 focus:ring-2 focus:ring-ring focus:outline-none"
            >
                Skip to main content
            </a>
            <AppSidebar />

            <SidebarInset id="main-content" tabIndex={-1}>
                <AppTopBar breadcrumbs={breadcrumbs} />

                <div className="flex flex-1 flex-col">{children}</div>
            </SidebarInset>
        </SidebarProvider>
    );
}

/**
 * Whether the column was open last time.
 *
 * The provider writes this cookie but never reads it, and Inertia remounts the
 * layout on every navigation — so without this, collapsing the sidebar lasted
 * exactly until the next link was clicked.
 */
function wasOpen(): boolean {
    if (typeof document === 'undefined') {
        return true;
    }

    return !/(?:^|;\s*)sidebar_state=false(?:;|$)/.test(document.cookie);
}
