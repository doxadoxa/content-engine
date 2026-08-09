import { Link } from '@inertiajs/react';
import { CalendarDays, Rows3 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { index as calendarIndex } from '@/routes/calendar';
import { index as contentIndex } from '@/routes/content';

/**
 * Two ways of looking at one thing.
 *
 * The calendar and the list were separate sections of the panel, which made
 * them read as separate bodies of work — an operator looking at an empty
 * August grid had no reason to think four articles were sitting in the other
 * one. They are the same units; only the shape differs, so the shape is a
 * toggle rather than a destination.
 */
export function PlanViews({ active }: { active: 'calendar' | 'list' }) {
    return (
        <div className="inline-flex items-center gap-1 rounded-full border bg-background/70 p-1 shadow-sm backdrop-blur-sm">
            <ViewLink
                href={calendarIndex().url}
                icon={CalendarDays}
                label="Calendar"
                active={active === 'calendar'}
            />
            <ViewLink
                href={contentIndex().url}
                icon={Rows3}
                label="List"
                active={active === 'list'}
            />
        </div>
    );
}

function ViewLink({
    href,
    icon: Icon,
    label,
    active,
}: {
    href: string;
    icon: typeof CalendarDays;
    label: string;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm transition-colors',
                active
                    ? 'bg-secondary font-medium text-secondary-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            {label}
        </Link>
    );
}
