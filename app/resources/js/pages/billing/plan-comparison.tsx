import { Form } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { workspacePanelClass } from '@/components/workspace-page';
import { checkout } from '@/routes/billing';
import type { Billing } from '@/types/billing';
import {
    allowanceDefinitions,
    allowanceLabel,
    allowanceValue,
} from './allowances';
import type { PlanLimit } from './allowances';
import { billingDate } from './current-plan';
import type { SubscriptionDetails } from './current-plan';

export type PlanCard = {
    key: string;
    version: number;
    currency: string;
    name: string;
    price_cents: number;
    limits: PlanLimit[];
    current: boolean;
    ai_frequency_days: number;
    ai_questions: number;
    change: {
        at_renewal: boolean;
        effective_at: string | null;
        schedules: { id: string; title: string; publish_at: string }[];
    };
};

type Props = {
    plans: PlanCard[];
    entitlement: Billing;
    details: SubscriptionDetails;
    hasProvider: boolean;
    canPay: boolean;
    trialDays: number;
    money: (cents: number, currency: string) => string;
};

export function PlanComparison({
    plans,
    entitlement,
    details,
    hasProvider,
    canPay,
    trialDays,
    money,
}: Props) {
    const rows = [
        ...new Set(
            plans.flatMap((plan) => plan.limits.map((limit) => limit.key)),
        ),
    ].filter((key) => key !== 'articles' && key !== 'social_posts');
    const currentLimits = new Map(
        details?.limits.map((limit) => [limit.key, limit.value]) ?? [],
    );

    return (
        <section
            id="available-plans"
            className="scroll-mt-6 space-y-5"
            aria-labelledby="available-plans-heading"
        >
            <div>
                <h2
                    id="available-plans-heading"
                    className="text-xl font-semibold"
                >
                    {entitlement.plan ? 'Compare plans' : 'Choose your plan'}
                </h2>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                    Both plans include topic research, article writing, a
                    content calendar, and scheduled publishing. Article and
                    usage allowances are per billing period.
                </p>
            </div>
            <div className="grid gap-4 lg:grid-cols-2 lg:gap-y-0">
                {plans.map((plan) => {
                    const limits = new Map(
                        plan.limits.map((limit) => [limit.key, limit]),
                    );
                    const reductions = plan.limits.filter(
                        (limit) =>
                            currentLimits.has(limit.key) &&
                            limit.value !== null &&
                            (currentLimits.get(limit.key) === null ||
                                limit.value < currentLimits.get(limit.key)!),
                    );

                    return (
                        <article
                            key={plan.key}
                            aria-label={`${plan.name} plan`}
                            className={`${workspacePanelClass} grid gap-0 overflow-hidden lg:row-span-[var(--plan-rows)] lg:grid-rows-subgrid ${plan.current ? 'ring-2 ring-primary' : ''}`}
                            style={
                                {
                                    '--plan-rows': rows.length + 3,
                                    '--feature-rows': rows.length,
                                } as CSSProperties
                            }
                        >
                            <header className="flex items-start justify-between gap-3 p-5 sm:p-6">
                                <div className="space-y-2">
                                    <h3 className="text-lg font-semibold">
                                        {plan.name}
                                    </h3>
                                    {plan.current && (
                                        <Badge variant="secondary">
                                            {entitlement.status === 'canceled'
                                                ? 'Previous plan'
                                                : entitlement.status ===
                                                    'trialing'
                                                  ? 'Selected after trial'
                                                  : 'Current plan'}
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-right text-2xl font-semibold tabular-nums">
                                    {money(plan.price_cents, plan.currency)}
                                    <span className="block text-sm font-normal text-muted-foreground">
                                        /month
                                    </span>
                                </p>
                            </header>
                            <div className="space-y-2 border-y bg-muted/20 px-5 py-4 sm:px-6">
                                <p className="text-2xl font-semibold">
                                    <span className="text-3xl tabular-nums">
                                        {allowanceValue(
                                            limits.get('articles')?.value,
                                        )}
                                    </span>{' '}
                                    articles
                                    <span className="mt-1 block text-sm font-normal text-muted-foreground">
                                        per billing period
                                    </span>
                                </p>
                                <p className="text-sm font-medium">
                                    {plan.ai_frequency_days === 7
                                        ? 'Weekly'
                                        : 'Monthly'}{' '}
                                    AI visibility checks
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {plan.ai_questions} questions across 4 AI
                                    services
                                </p>
                            </div>
                            <dl className="grid lg:row-span-[var(--feature-rows)] lg:grid-rows-subgrid">
                                {rows.map((key) => (
                                    <div
                                        key={key}
                                        data-feature={key}
                                        className="flex items-start justify-between gap-4 border-b px-5 py-3 text-sm sm:px-6"
                                    >
                                        <dt className="text-muted-foreground">
                                            {allowanceLabel(
                                                key,
                                                limits.get(key)?.label,
                                            )}
                                        </dt>
                                        <dd className="text-right font-medium tabular-nums">
                                            {allowanceValue(
                                                limits.get(key)?.value,
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            <div className="flex flex-col gap-4 p-5 sm:p-6">
                                {!plan.current &&
                                    reductions.length > 0 &&
                                    entitlement.plan && (
                                        <details className="rounded-xl border p-3 text-sm">
                                            <summary className="cursor-pointer font-medium">
                                                {reductions.length} lower
                                                allowances than{' '}
                                                {entitlement.plan.name}
                                            </summary>
                                            <ul className="mt-3 space-y-2 text-muted-foreground">
                                                {reductions.map((limit) => (
                                                    <li key={limit.key}>
                                                        {allowanceLabel(
                                                            limit.key,
                                                            limit.label,
                                                        )}
                                                        :{' '}
                                                        {allowanceValue(
                                                            currentLimits.get(
                                                                limit.key,
                                                            ),
                                                        )}{' '}
                                                        →{' '}
                                                        {allowanceValue(
                                                            limit.value,
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        </details>
                                    )}
                                <div className="mt-auto">
                                    {canPay && !plan.current ? (
                                        <PlanAction
                                            plan={plan}
                                            hasProvider={hasProvider}
                                            hasPlan={entitlement.plan !== null}
                                            trialDays={trialDays}
                                        />
                                    ) : plan.current ? (
                                        <p className="text-sm text-muted-foreground">
                                            {entitlement.status === 'trialing'
                                                ? 'Your selected plan after the trial.'
                                                : entitlement.status ===
                                                    'canceled'
                                                  ? 'Your previous subscription.'
                                                  : 'Your current package is shown above.'}
                                        </p>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Ask the project owner to change
                                            plans.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </article>
                    );
                })}
            </div>
            <div className="grid gap-3 text-sm leading-6 text-muted-foreground sm:grid-cols-2">
                <p>
                    <strong className="font-medium text-foreground">
                        Assistant messages:{' '}
                    </strong>
                    {allowanceDefinitions.assistant_turns}
                </p>
                <p>
                    <strong className="font-medium text-foreground">
                        AI answer attempts:{' '}
                    </strong>
                    {allowanceDefinitions.ai_answers}
                </p>
            </div>
        </section>
    );
}

function PlanAction({
    plan,
    hasProvider,
    hasPlan,
    trialDays,
}: {
    plan: PlanCard;
    hasProvider: boolean;
    hasPlan: boolean;
    trialDays: number;
}) {
    return (
        <Form action={checkout()} method="post" className="space-y-4">
            {({ processing }) => (
                <>
                    <input type="hidden" name="plan" value={plan.key} />
                    <input
                        type="hidden"
                        name="plan_version"
                        value={plan.version}
                    />
                    <p className="text-sm leading-6 text-muted-foreground">
                        {hasProvider
                            ? plan.change.at_renewal
                                ? `Starts at renewal${plan.change.effective_at ? ` on ${billingDate(plan.change.effective_at)}` : ''}. Your existing allowance applies until then.`
                                : 'Applies immediately. The price difference is prorated; usage already counted stays counted.'
                            : hasPlan
                              ? 'Continue to checkout to review and confirm the subscription. Your existing plan applies until checkout is completed.'
                              : `Review and confirm at checkout. Eligible new customers receive a ${trialDays}-day trial.`}
                    </p>
                    {hasProvider && plan.change.at_renewal && (
                        <div className="space-y-3 rounded-xl border p-3 text-sm">
                            <p>
                                Approved articles stay available to publish.
                                Unapproved drafts use the new allowance; extra
                                drafts wait for capacity.
                            </p>
                            {plan.change.schedules.length > 0 ? (
                                <details>
                                    <summary className="cursor-pointer font-medium">
                                        Review {plan.change.schedules.length}{' '}
                                        scheduled articles after renewal
                                    </summary>
                                    <ul className="mt-2 space-y-2">
                                        {plan.change.schedules.map(
                                            (schedule) => (
                                                <li key={schedule.id}>
                                                    {schedule.title} ·{' '}
                                                    {billingDate(
                                                        schedule.publish_at,
                                                    )}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </details>
                            ) : (
                                <p className="text-muted-foreground">
                                    No articles are scheduled after renewal.
                                </p>
                            )}
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    name="acknowledge_downgrade"
                                    value="1"
                                    required
                                    className="mt-1"
                                />
                                <span>
                                    I understand the lower allowance and its
                                    effect on my calendar.
                                </span>
                            </label>
                        </div>
                    )}
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing
                            ? 'Opening billing…'
                            : hasProvider
                              ? plan.change.at_renewal
                                  ? `Choose ${plan.name} at renewal`
                                  : `Upgrade to ${plan.name}`
                              : `Continue with ${plan.name}`}
                    </Button>
                </>
            )}
        </Form>
    );
}
