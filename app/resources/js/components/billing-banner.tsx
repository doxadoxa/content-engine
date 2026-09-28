import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, CreditCard, Gift } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatPlanPrice } from '@/lib/money';
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

    if (notice.quiet) {
        // One quiet line of information, not a call to action. No tint and
        // no button: somebody reading about a trial that is going exactly as
        // they arranged it should not be made to feel something needs doing.
        return (
            <div
                role="status"
                className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b px-4 py-1.5 text-xs text-muted-foreground sm:px-6"
            >
                <notice.icon className="size-3.5 shrink-0" aria-hidden="true" />
                <p className="min-w-0 flex-1">{notice.message}</p>
                {notice.action && (
                    <Link
                        href={notice.action.href}
                        className="font-medium text-foreground underline-offset-4 hover:underline"
                    >
                        {notice.action.label}
                    </Link>
                )}
            </div>
        );
    }

    return (
        <div
            role="status"
            className={`flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-2.5 text-sm sm:px-6 ${notice.tone ?? ''}`}
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
    /** The tinted treatment; unused by a quiet notice. */
    tone?: string;
    message: string;
    action?: { href: string; label: string };
    /**
     * Information rather than a problem: drawn as a small muted line with a
     * text link, so the tinted treatment keeps meaning something is wrong.
     */
    quiet?: boolean;
};

/**
 * What to say, if anything.
 *
 * A working subscription says nothing at all. A countdown that runs every day
 * of a paid month is noise, and noise is what makes somebody stop reading the
 * line that eventually matters.
 */
function noticeFor(billing: Billing): Notice | null {
    // The sample, before anything else and in a different colour.
    //
    // This is the one state in here that is not a problem: somebody has just
    // finished setting up, an article written from their own site is sitting
    // on the dashboard behind this line, and the next step is a card. Rendered
    // in amber beside a warning triangle — which is what "no subscription"
    // looked like and what it was read as — it turned the best moment in the
    // product into an error message.
    if (billing.preview) {
        return {
            icon: Gift,
            tone: 'border-sky-500/30 bg-sky-500/10 text-sky-900 dark:text-sky-200',
            message: billing.preview_finished
                ? 'This is your free sample. Add a card to start the trial — nothing is charged today.'
                : 'Writing your sample now. Nothing is charged, and no card is needed to read it.',
            action: billing.preview_finished
                ? { href: billingIndex().url, label: 'Start my trial' }
                : undefined,
        };
    }

    if (billing.refusal) {
        // Each reason gets its own button, because they are not the same
        // problem: a card that failed is a payment method, an ended trial is a
        // price, and a quota that ran out is neither. The first version of this
        // reasoned its way to giving `past_due` no button at all, which left a
        // customer whose card bounced looking at a stopped engine with no route
        // to the portal — the exact case that most needs one.
        const failed = billing.refusal.code === 'past_due';

        return {
            icon: failed ? CreditCard : AlertTriangle,
            tone: 'border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200',
            message: billing.refusal.message,
            // No plan to choose for somebody whose trial already converts to
            // one they chose — the cost ceiling can stop a trial, and "Choose
            // a plan" under it was the wrong question again.
            action:
                billing.refusal.code === 'quota' ||
                (billing.converts_after_trial && !failed)
                    ? undefined
                    : {
                          href: billingIndex().url,
                          label: failed ? 'Fix payment' : 'Choose a plan',
                      },
        };
    }

    // A quota that ran out, when nothing worse is wrong. Not a refusal — the
    // engine is still running and still making everything else — but the
    // operator has to hear it somewhere, and until this existed the only
    // surface was a progress bar on a page they had no reason to open.
    //
    // `shortfalls` rather than `exhausted`. The content plan and the site
    // audit are made once a period by the engine itself, so a used-up counter
    // there means the work got done — and "you have used this period's
    // content plans" in amber, to a customer who had just paid, read as an
    // error. A converting trial's sample-sized allowance is left out too.
    if (billing.shortfalls.length > 0) {
        const names = billing.shortfalls
            .map((metric) => metric.replaceAll('_', ' '))
            .join(' and ');

        return {
            icon: AlertTriangle,
            tone: 'border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200',
            message: `You have used this period's ${names}. Everything else is still running.`,
            action: { href: billingIndex().url, label: 'See plans' },
        };
    }

    if (billing.status === 'trialing' && billing.trial_ends_at) {
        return trialNotice(billing, billing.trial_ends_at);
    }

    return null;
}

/**
 * The trial line, in whichever of its three shapes applies.
 *
 * A public trial is a paid plan with free days on the front: somebody chose
 * Growth, added a card, and was then told "your trial ends in 3 days" beside
 * a "Choose a plan" button — asked to make a decision they had already made.
 * So a trial that will convert says what happens next and when, and asks
 * nothing. Only the legacy bare trial, where no plan was ever picked, still
 * counts down to a choice.
 */
function trialNotice(billing: Billing, endsAt: string): Notice {
    const quiet = { icon: Clock, quiet: true };
    const today = isToday(endsAt);
    const date = new Date(endsAt).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
    const billingLink = { href: billingIndex().url, label: 'Billing' };

    if (billing.converts_after_trial && billing.plan) {
        const price =
            billing.plan.price_cents > 0
                ? ` at ${formatPlanPrice(billing.plan.price_cents, billing.plan.currency)}/month`
                : '';
        // The trial's allowance is sample-sized by design, so running out of
        // its articles is the trial doing its job. Said as news, not a limit.
        const articles = billing.usage.articles;
        const ready =
            articles?.limit && articles.remaining === 0
                ? ' Your trial articles are ready.'
                : '';

        return {
            ...quiet,
            message: today
                ? `Your ${billing.plan.name} plan starts today${price}.${ready}`
                : `You’re on the ${billing.plan.name} trial. Your plan starts ${date}${price}.${ready}`,
            action: billingLink,
        };
    }

    // A chosen plan that will not convert — cancelled before the trial ended,
    // or a trial with no Stripe subscription behind it: still trialing until
    // the date, and then nothing. Said plainly so it is not a surprise.
    if (billing.plan && billing.plan.key !== 'trial') {
        return {
            ...quiet,
            message: `Your trial ends ${today ? 'today' : date}. Your plan will not start.`,
            action: billingLink,
        };
    }

    // The legacy bare trial: nobody has chosen a plan, so this one really is
    // a countdown to a decision, and keeps the tint and the button that say so.
    const left = daysUntil(endsAt);

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

/** Whether the instant falls on the viewer's own calendar today. */
function isToday(iso: string): boolean {
    return new Date(iso).toDateString() === new Date().toDateString();
}

/** Whole days, rounded up, so "ends in 1 day" never means "ended". */
function daysUntil(iso: string): number {
    const ms = new Date(iso).getTime() - Date.now();

    return Math.max(0, Math.ceil(ms / 86_400_000));
}
