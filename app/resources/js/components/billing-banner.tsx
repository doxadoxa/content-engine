import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, CreditCard } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { index as billingIndex } from '@/routes/billing';
import type { Billing } from '@/types/billing';

/**
 * One line above the work, and only when there is something to say.
 *
 * It renders from the shared props rather than from a per-page prop, because
 * the thing it is about — whether the engine is allowed to run — is true of the
 * whole session and not of the screen you happen to be on. A banner passed down
 * page by page would be missing from exactly the screens somebody forgot,
 * which are the screens with the buttons on them.
 *
 * Deliberately not a modal and deliberately not a blocked screen. Everything a
 * project ever made stays readable when it stops paying; taking the work away
 * to talk about the invoice would be punishing somebody for a card that
 * expired.
 */
export function BillingBanner() {
    const billing = usePage().props.billing as Billing | null;

    if (!billing) {
        return null;
    }

    const notice = noticeFor(billing);

    if (!notice) {
        return null;
    }

    return (
        <div
            role="status"
            className={`flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-2.5 text-sm sm:px-6 ${notice.tone}`}
        >
            <notice.icon className="size-4 shrink-0" aria-hidden="true" />
            <p className="min-w-0 flex-1">{notice.message}</p>
            {notice.action && (
                <Button asChild size="sm" variant="outline">
                    <Link href={notice.action.href}>{notice.action.label}</Link>
                </Button>
            )}
        </div>
    );
}

type Notice = {
    icon: typeof Clock;
    tone: string;
    message: string;
    action?: { href: string; label: string };
};

/**
 * What to say, if anything.
 *
 * A working subscription says nothing at all. A countdown that runs every day
 * of a paid month is noise, and noise is what makes somebody stop reading the
 * line that eventually matters.
 */
function noticeFor(billing: Billing): Notice | null {
    if (billing.refusal) {
        // Each reason gets its own button, because they are not the same
        // problem: a card that failed is a payment method, an ended trial is a
        // price, and a quota that ran out is neither.
        const upgrade =
            billing.refusal.code === 'past_due'
                ? undefined
                : { href: billingIndex().url, label: 'Choose a plan' };

        return {
            icon:
                billing.refusal.code === 'past_due'
                    ? CreditCard
                    : AlertTriangle,
            tone: 'border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200',
            message: billing.refusal.message,
            action: billing.refusal.code === 'quota' ? undefined : upgrade,
        };
    }

    if (billing.status === 'trialing' && billing.trial_ends_at) {
        const left = daysUntil(billing.trial_ends_at);

        return {
            icon: Clock,
            tone: 'border-sky-500/30 bg-sky-500/10 text-sky-900 dark:text-sky-200',
            message:
                left <= 0
                    ? 'Your trial ends today.'
                    : `Your trial ends in ${left} day${left === 1 ? '' : 's'}.`,
            action: { href: billingIndex().url, label: 'Choose a plan' },
        };
    }

    return null;
}

/** Whole days, rounded up, so "ends in 1 day" never means "ended". */
function daysUntil(iso: string): number {
    const ms = new Date(iso).getTime() - Date.now();

    return Math.max(0, Math.ceil(ms / 86_400_000));
}
