import { Link } from '@inertiajs/react';
import { create, index } from '@/routes/social';
import { plan } from '@/routes/social';

/**
 * The social surface's three views.
 *
 * One surface with tabs rather than three entries in the navigation column,
 * because they are three questions about the same month and an operator asked
 * to choose between them from a sidebar has to already know the answer. The
 * previous arrangement put "Social" and "Studio" side by side in the column
 * with nothing to distinguish them, which is the confusion this replaces.
 *
 * Ordered by how often they are opened, not by the order the work happens in:
 * the board is a daily screen, creating is a few times a week, and the month's
 * plan is built once and revisited when it is wrong.
 */
export function SocialTabs({
    current,
    month,
}: {
    current: 'overview' | 'create' | 'plan';
    /** Carried across, so switching tabs does not silently jump to today. */
    month: string;
}) {
    const tabs = [
        {
            key: 'overview' as const,
            label: 'Overview',
            href: index({ query: { month } }),
        },
        {
            key: 'create' as const,
            label: 'Create',
            href: create({ query: { month } }),
        },
        {
            key: 'plan' as const,
            label: 'Plan',
            href: plan({ query: { month } }),
        },
    ];

    return (
        <nav aria-label="Social views">
            <ul className="inline-flex min-w-0 flex-wrap items-center gap-1 rounded-xl bg-muted/45 p-1">
                {tabs.map((tab) => (
                    <li key={tab.key}>
                        <Link
                            href={tab.href}
                            aria-current={
                                tab.key === current ? 'page' : undefined
                            }
                            className={`flex min-h-10 items-center rounded-lg px-4 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:outline-none ${
                                tab.key === current
                                    ? 'bg-background font-semibold text-foreground shadow-sm'
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
