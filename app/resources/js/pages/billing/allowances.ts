import type { BillingMetric } from '@/types/billing';

export type PlanLimit = { key: string; label: string; value: number | null };

const labels: Record<string, string> = {
    articles: 'Articles',
    ai_answers: 'AI answer attempts',
    assistant_turns: 'Assistant messages',
    content_plans: 'Content plans',
    page_improvements: 'Page improvements',
    site_audits: 'Website checks',
    tracked_pages: 'Monitored pages',
    seats: 'Team members',
    locales: 'Languages',
    channels: 'Website connections',
};

export const allowanceLabel = (key: string, fallback = key) =>
    labels[key] ?? fallback;

export const allowanceDefinitions: Partial<Record<BillingMetric, string>> = {
    assistant_turns:
        'One message you send to the Avyo assistant, counted even if its reply fails.',
    ai_answers:
        'One question sent to one AI service. Failed attempts count too. Scheduled checks and checks you start yourself use this same allowance.',
};

export const allowanceValue = (value: number | null | undefined) =>
    value === undefined
        ? 'Not specified'
        : value === null
          ? 'Unlimited'
          : value === 0
            ? 'Not included'
            : value.toLocaleString();
