import { Link } from '@inertiajs/react';
import { workspacePanelClass } from '@/components/workspace-page';

export type SamplingAllowance = {
    frequency: 'monthly' | 'weekly';
    questions: number;
    services: number;
    used: number;
    reserved: number;
    limit: number;
    remaining: number;
    period_ends_at: string | null;
    next_check_at: string | null;
    available: boolean;
    note: string;
};

export function SamplingAllowancePanel({
    allowance,
}: {
    allowance: SamplingAllowance | null;
}) {
    if (!allowance) {
        return null;
    }

    return (
        <section className={`${workspacePanelClass} p-5 text-sm`}>
            <h2 className="font-semibold">
                {allowance.frequency === 'monthly' ? 'Monthly' : 'Weekly'} AI
                visibility checks
            </h2>
            <p className="mt-2">
                Up to {allowance.questions} saved questions across{' '}
                {allowance.services} services. {allowance.remaining} of{' '}
                {allowance.limit} answer checks remain this billing period
                {allowance.reserved > 0
                    ? `; ${allowance.reserved} checks are reserved for work in progress`
                    : ''}
                .
            </p>
            <p className="mt-2 text-muted-foreground">{allowance.note}</p>
            <p className="mt-2 text-xs text-muted-foreground">
                {allowance.period_ends_at && (
                    <>
                        Allowance renews{' '}
                        {new Date(
                            allowance.period_ends_at,
                        ).toLocaleDateString()}
                        .{' '}
                    </>
                )}
                {allowance.available && allowance.next_check_at && (
                    <>
                        Next scheduled check:{' '}
                        {new Date(allowance.next_check_at).toLocaleDateString()}{' '}
                        (or the next hourly check if already due).{' '}
                    </>
                )}
                {!allowance.available && (
                    <>
                        New checks are waiting for an available allowance and an
                        active project.{' '}
                    </>
                )}
                <Link href="/billing" className="underline">
                    View plan and usage
                </Link>
            </p>
        </section>
    );
}
