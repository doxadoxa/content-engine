import { Form } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { workspacePanelClass } from '@/components/workspace-page';
import { portal } from '@/routes/billing';
import type { Billing } from '@/types/billing';
import type { PlanLimit } from './allowances';

export type SubscriptionDetails = {
    period_started_at: string;
    is_legacy: boolean;
    is_custom: boolean;
    limits: PlanLimit[];
} | null;

export const billingDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : 'Not set';

function periodDates(start: string | undefined, end: string | null) {
    if (!start) {
        return billingDate(end);
    }

    if (!end) {
        return `${billingDate(start)} · end date not set`;
    }

    if (new Date(start) > new Date(end)) {
        return billingDate(end);
    }

    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).formatRange(new Date(start), new Date(end));
}

export function CurrentPlan({
    entitlement,
    details,
    canPay,
    hasProvider,
    money,
}: {
    entitlement: Billing;
    details: SubscriptionDetails;
    canPay: boolean;
    hasProvider: boolean;
    money: (cents: number, currency: string) => string;
}) {
    const { plan, status, refusal, usage } = entitlement;
    const trial = status === 'trialing';
    const ended = refusal?.code === 'trial_ended';
    const canceled = status === 'canceled';
    const available = entitlement.may_generate && refusal === null && !canceled;
    const articles = usage.articles;
    const statusLabel = ended
        ? 'Trial ended'
        : trial
          ? 'Free trial'
          : status === 'past_due'
            ? 'Payment overdue'
            : canceled
              ? 'Canceled'
              : 'Active';
    const explanation = !plan
        ? 'Choose a plan to start creating and publishing content.'
        : canceled
          ? 'This subscription has ended. Your content and reports remain available.'
          : ended
            ? 'Your trial has ended. Review your billing details to continue.'
            : trial
              ? 'Trial allowances apply during this period.'
              : null;

    return (
        <section
            aria-label="Your current plan"
            className={`${workspacePanelClass} overflow-hidden`}
        >
            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-4 p-5 sm:p-6">
                <div className="min-w-0 space-y-2">
                    <p className="text-sm text-muted-foreground">
                        {canceled ? 'Your previous plan' : 'Your current plan'}
                    </p>
                    <h2 className="text-2xl font-semibold tracking-tight break-words sm:text-3xl">
                        {plan?.name ?? 'No plan selected'}
                    </h2>
                    <div className="flex flex-wrap gap-2">
                        {plan && (
                            <Badge variant="secondary">{statusLabel}</Badge>
                        )}
                        {details?.is_legacy && (
                            <Badge variant="outline">Earlier plan</Badge>
                        )}
                    </div>
                </div>
                {plan && (
                    <div className="max-w-36 space-y-2 text-right sm:max-w-none">
                        <p className="text-sm text-muted-foreground">
                            {trial && plan.key !== 'trial'
                                ? 'Listed price after trial'
                                : 'Listed plan price'}
                        </p>
                        <p className="text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">
                            {details?.is_custom
                                ? 'Custom pricing'
                                : plan.key === 'trial'
                                  ? 'Free'
                                  : money(plan.price_cents, plan.currency)}
                            {!details?.is_custom && plan.key !== 'trial' && (
                                <span className="block text-sm font-normal text-muted-foreground sm:ml-1 sm:inline">
                                    /month
                                </span>
                            )}
                        </p>
                    </div>
                )}
                {explanation && (
                    <p className="col-span-2 text-sm leading-6 text-muted-foreground">
                        {explanation}
                    </p>
                )}
            </div>
            {plan && (
                <>
                    <p
                        className="border-t px-5 py-3 text-sm leading-6 sm:px-6"
                        aria-label="Billing setup"
                    >
                        {hasProvider
                            ? 'Payment details, invoices and tax are available in Manage billing.'
                            : 'Automatic billing isn’t configured for this project.'}
                    </p>
                    <div className="space-y-4 border-t bg-muted/20 p-5 sm:p-6">
                        <div aria-label="Article allowance">
                            <p className="text-xl font-semibold tracking-tight sm:text-2xl">
                                {!articles ? (
                                    'Article allowance unavailable'
                                ) : articles.limit === 0 ? (
                                    'Articles not included'
                                ) : available ? (
                                    articles.remaining === null ? (
                                        'Unlimited articles'
                                    ) : (
                                        <>
                                            <span className="text-3xl tabular-nums sm:text-4xl">
                                                {articles.remaining.toLocaleString()}
                                            </span>{' '}
                                            articles remaining
                                        </>
                                    )
                                ) : (
                                    <>
                                        <span className="text-3xl tabular-nums sm:text-4xl">
                                            {articles.used.toLocaleString()}
                                        </span>{' '}
                                        articles approved
                                    </>
                                )}
                            </p>
                            {articles && (
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {available
                                        ? `${articles.used.toLocaleString()}${articles.limit === null ? '' : ` of ${articles.limit.toLocaleString()}`} approved ${trial ? 'during this trial' : 'this period'}.`
                                        : `${articles.limit === null ? 'Unlimited' : articles.limit.toLocaleString()} articles included in this ${trial ? 'trial' : 'period'}.`}
                                </p>
                            )}
                        </div>
                        <p className="text-sm leading-6 text-muted-foreground">
                            <span className="font-medium text-foreground">
                                {trial
                                    ? 'Trial period'
                                    : canceled
                                      ? 'Last billing period'
                                      : 'Current period'}
                                :{' '}
                            </span>
                            {periodDates(
                                details?.period_started_at,
                                trial
                                    ? entitlement.trial_ends_at
                                    : entitlement.period_ends_at,
                            )}
                        </p>
                    </div>
                </>
            )}
            <div className="flex flex-wrap items-center justify-end gap-3 border-t px-5 py-4 sm:px-6">
                {!canPay && (
                    <p className="mr-auto text-sm text-muted-foreground">
                        Only the project owner can change the plan or payment
                        details.
                    </p>
                )}
                {canPay && hasProvider && (
                    <Form action={portal()} method="post">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Manage billing
                            </Button>
                        )}
                    </Form>
                )}
                <Button asChild variant="outline">
                    <a href="#available-plans">
                        {plan ? 'Compare available plans' : 'Choose a plan'}
                    </a>
                </Button>
            </div>
        </section>
    );
}
