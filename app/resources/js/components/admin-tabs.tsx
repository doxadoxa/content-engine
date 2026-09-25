import { Link } from '@inertiajs/react';
import { overview, projects, subscriptions, users } from '@/routes/admin';

export type AdminSection = 'overview' | 'projects' | 'users' | 'subscriptions';

/**
 * The service's four administration screens.
 *
 * They had no navigation at all until now. The only way in is the avatar menu,
 * which lands on the overview, and the overview's only outbound links were the
 * project rows inside its usage table — so on a deployment with no projects yet
 * the panel was a cul-de-sac and its other three screens could be reached only
 * by typing the address. That is the bug this fixes; it is not a redesign.
 *
 * Tabs rather than entries in the sidebar column. The sidebar is the
 * operator's, and administration is deliberately not on it: an ordinary
 * customer gains nothing by learning that `/admin` is a real address on this
 * deployment.
 *
 * Ordered by what somebody arriving here is usually answering. The overview is
 * where the menu lands. Projects is where every action lives — a plan, a trial,
 * a pause. Accounts answers "who is this person". Subscriptions is the
 * exception screen, opened when a webhook is suspected lost.
 */
export function AdminTabs({
    current,
    exact = true,
}: {
    current: AdminSection;
    /**
     * False on a screen that sits *below* one of the tabs rather than being it,
     * such as a single project. The tab is then drawn and announced as the
     * section you are inside rather than as the page you are on — a filled tab
     * on a project's own page would say you are on the projects list, which is
     * the thing you would click it to reach.
     */
    exact?: boolean;
}) {
    const tabs = [
        { key: 'overview' as const, label: 'Overview', href: overview() },
        { key: 'projects' as const, label: 'Projects', href: projects() },
        { key: 'users' as const, label: 'Accounts', href: users() },
        {
            key: 'subscriptions' as const,
            label: 'Subscriptions',
            href: subscriptions(),
        },
    ];

    return (
        <nav aria-label="Administration screens">
            <ul className="inline-flex min-w-0 flex-wrap items-center gap-1 rounded-xl bg-muted/45 p-1">
                {tabs.map((tab) => (
                    <li key={tab.key}>
                        <Link
                            href={tab.href}
                            aria-current={
                                tab.key === current
                                    ? exact
                                        ? 'page'
                                        : 'true'
                                    : undefined
                            }
                            className={`flex min-h-10 items-center rounded-lg px-4 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                                tab.key === current
                                    ? exact
                                        ? 'bg-background font-semibold text-foreground shadow-sm'
                                        : 'font-medium text-foreground hover:bg-background/50'
                                    : 'text-muted-foreground hover:bg-background/50 hover:text-foreground'
                            }`}
                        >
                            {tab.label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
