import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronRight,
    CircleDot,
    FileCheck2,
    Gauge,
    LayoutDashboard,
    RefreshCw,
    Send,
    ShieldCheck,
    Sparkles,
    TrendingUp,
    WandSparkles,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { login } from '@/routes';

type Feature = {
    title: string;
    description: string;
};

const channels = [
    'Google',
    'ChatGPT',
    'Your blog',
    'Threads',
    'LinkedIn',
    'Instagram',
];

const workflow = [
    {
        number: '01',
        title: 'Give Avyo your point of view',
        description:
            'Share your site, audience, offers, voice, and boundaries once. Avyo turns them into a living brand brief that guides every decision.',
        detail: 'Brand, audience, offers, voice',
    },
    {
        number: '02',
        title: 'Get a strategy, not a prompt',
        description:
            'Avyo finds demand, maps the questions your buyers ask, and builds one connected monthly plan for search and social.',
        detail: 'Topics, keywords, channels, cadence',
    },
    {
        number: '03',
        title: 'Review once, grow everywhere',
        description:
            'Approve polished work from one queue. Avyo publishes to connected channels, measures the response, and improves what comes next.',
        detail: 'Draft, approve, publish, learn',
    },
];

const engineFeatures: Feature[] = [
    {
        title: 'Demand-led planning',
        description:
            'Turn real search demand, competitive gaps, and audience questions into a focused 30-day plan.',
    },
    {
        title: 'Built for AI discovery',
        description:
            'Structured answers, entity coverage, and citation-ready passages help your expertise travel beyond traditional search.',
    },
    {
        title: 'Channel-native social',
        description:
            'One core idea becomes posts and carousels shaped for the way each channel is actually consumed.',
    },
    {
        title: 'Multilingual by design',
        description:
            'Plan, create, and connect local versions without flattening every market into a literal translation.',
    },
    {
        title: 'Human control built in',
        description:
            'Nothing has to go live unseen. Quality checks, previews, approvals, and delivery history stay visible.',
    },
    {
        title: 'A system that learns',
        description:
            'Search performance and visibility signals flow back into planning, so Avyo can refresh fading work and compound what wins.',
    },
];

const faq = [
    {
        question: 'What is Avyo?',
        answer: 'Avyo is an organic growth workspace that connects strategy, search content, social content, publishing, and performance. It gives a small team the operating rhythm of a much larger content function.',
    },
    {
        question: 'Is Avyo just another AI writer?',
        answer: 'No. A writer stops at a draft. Avyo starts with your brand and market, builds a connected plan, creates channel-specific work, keeps a human approval step, publishes through verified connections, and uses performance to guide the next cycle.',
    },
    {
        question: 'Can I review content before it publishes?',
        answer: 'Yes. Review is a first-class part of the workflow. You can approve, revise, reject, replace imagery, and choose which connected channels are allowed to publish automatically.',
    },
    {
        question: 'How does Avyo keep our brand voice consistent?',
        answer: 'Your positioning, audience, tone, examples, visual direction, and guardrails live in a versioned brand brief. Every plan and draft is created from the current brief, so your strategy stays editable and traceable.',
    },
    {
        question: 'Can Avyo work across languages and channels?',
        answer: 'Yes. Locales are connected parts of the same content system, and social posts inherit the message and source material of their parent idea while adapting the format for each channel.',
    },
];

function BrandMark({ inverse = false }: { inverse?: boolean }) {
    return (
        <span className="inline-flex items-center gap-2.5">
            <AppLogoIcon
                tone={inverse ? 'cream' : 'ink'}
                className="size-8 shrink-0"
            />
            <span className="text-[19px] font-semibold tracking-[-0.04em]">
                Avyo
            </span>
        </span>
    );
}

function ArrowLink({ children }: { children: React.ReactNode }) {
    return (
        <span className="inline-flex items-center gap-2">
            {children}
            <ArrowRight className="size-4 transition-transform group-hover:translate-x-1" />
        </span>
    );
}

function ProductPreview() {
    return (
        <div
            role="img"
            aria-label="Avyo workspace preview showing a weekly content proposal, channel drafts, quality checks, publishing status, and visibility feedback."
            className="relative mx-auto mt-14 max-w-[1120px] px-3 sm:mt-20 sm:px-6 lg:px-8"
        >
            <div
                aria-hidden="true"
                className="absolute top-[-8%] left-[12%] h-[62%] w-[76%] rounded-full bg-[#d6533c]/16 blur-[80px]"
            />

            <div className="relative rounded-[22px] border border-[#cbc5bd] bg-[#17352f] p-1.5 shadow-[0_35px_90px_rgba(30,25,50,0.2)] sm:rounded-[30px] sm:p-2.5">
                <div className="overflow-hidden rounded-[16px] bg-[#f8f2e8] sm:rounded-[21px]">
                    <div className="flex h-10 items-center gap-1.5 border-b border-[#e2ded8] bg-white px-4 sm:h-12 sm:px-5">
                        <span className="size-2.5 rounded-full bg-[#ff7463]" />
                        <span className="size-2.5 rounded-full bg-[#f5c64d]" />
                        <span className="size-2.5 rounded-full bg-[#63c979]" />
                        <div className="mx-auto hidden h-6 w-48 items-center justify-center rounded-md bg-[#f2eadc] text-[9px] text-[#807b73] sm:flex">
                            app.avyo.ai
                        </div>
                    </div>

                    <div className="grid min-h-[430px] grid-cols-[58px_1fr] sm:min-h-[560px] sm:grid-cols-[190px_1fr]">
                        <aside className="border-r border-[#e2ded8] bg-white px-2 py-4 sm:px-4 sm:py-5">
                            <div className="mb-6 flex items-center gap-2 px-1 sm:px-2">
                                <AppLogoIcon
                                    tone="ink"
                                    className="size-7 shrink-0"
                                />
                                <span className="hidden text-sm font-semibold sm:block">
                                    Avyo
                                </span>
                            </div>
                            <div className="space-y-1.5">
                                {[
                                    [LayoutDashboard, 'Overview'],
                                    [CalendarDays, 'Plan'],
                                    [WandSparkles, 'Studio'],
                                    [FileCheck2, 'Approvals'],
                                    [Gauge, 'Visibility'],
                                ].map(([Icon, label], index) => {
                                    const SidebarIcon = Icon as LucideIcon;

                                    return (
                                        <div
                                            key={label as string}
                                            className={`flex h-9 items-center gap-2.5 rounded-lg px-2 ${index === 2 ? 'bg-[#f2d9d0] text-[#a13220]' : 'text-[#8c877f]'}`}
                                        >
                                            <SidebarIcon className="size-4 shrink-0" />
                                            <span className="hidden text-[11px] font-medium sm:block">
                                                {label as string}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                            <div className="mt-auto hidden border-t border-[#eeeae5] pt-4 sm:block">
                                <div className="flex items-center gap-2 text-[10px] text-[#79736c]">
                                    <span className="size-2 rounded-full bg-[#4fb968]" />
                                    Engine active
                                </div>
                            </div>
                        </aside>

                        <div className="min-w-0 p-3 sm:p-6 lg:p-8">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-[9px] font-semibold tracking-[0.16em] text-[#7a746d] uppercase sm:text-[10px]">
                                        Content studio
                                    </p>
                                    <h3 className="mt-1 text-base font-semibold tracking-[-0.03em] text-[#1a191d] sm:text-2xl">
                                        Build next week&apos;s presence
                                    </h3>
                                </div>
                                <span className="hidden rounded-full border border-[#ded9d2] bg-white px-3 py-1.5 text-[10px] font-medium text-[#514c47] sm:inline-flex">
                                    Aug 12–18
                                </span>
                            </div>

                            <div className="mt-4 grid gap-3 sm:mt-6 sm:grid-cols-[1.15fr_.85fr] sm:gap-4">
                                <div className="rounded-xl border border-[#ded9d2] bg-white p-3 shadow-sm sm:rounded-2xl sm:p-5">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="flex size-7 items-center justify-center rounded-lg bg-[#f2d9d0] text-[#d6533c]">
                                                <Sparkles className="size-3.5" />
                                            </span>
                                            <div>
                                                <p className="text-[10px] font-semibold text-[#28262b] sm:text-xs">
                                                    Avyo&apos;s proposal
                                                </p>
                                                <p className="hidden text-[9px] text-[#8b857e] sm:block">
                                                    Based on demand + brand
                                                    brief
                                                </p>
                                            </div>
                                        </div>
                                        <span className="rounded-full bg-[#ecf7ee] px-2 py-1 text-[8px] font-semibold text-[#2e7540] sm:text-[9px]">
                                            Ready
                                        </span>
                                    </div>

                                    <div className="mt-4 rounded-xl bg-[#f3ecdd] p-3 sm:mt-5 sm:p-4">
                                        <div className="flex items-center gap-1.5 text-[8px] font-semibold tracking-[0.12em] text-[#766f68] uppercase sm:text-[9px]">
                                            <span className="size-1.5 rounded-full bg-[#3155a5]" />
                                            Core idea
                                        </div>
                                        <p className="mt-2 text-xs leading-snug font-semibold tracking-[-0.01em] text-[#27242a] sm:text-base">
                                            Why answer-ready content wins the
                                            next era of search
                                        </p>
                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                            {[
                                                'AI discovery',
                                                'Explainer',
                                                'EN + PT',
                                            ].map((tag) => (
                                                <span
                                                    key={tag}
                                                    className="rounded-md border border-[#e2ddd7] bg-white px-1.5 py-1 text-[7px] text-[#716b65] sm:px-2 sm:text-[8px]"
                                                >
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="mt-3 grid grid-cols-3 gap-1.5 sm:mt-4 sm:gap-2">
                                        {[
                                            ['Blog', '1 article'],
                                            ['Threads', '3 posts'],
                                            ['LinkedIn', '1 carousel'],
                                        ].map(([label, value]) => (
                                            <div
                                                key={label}
                                                className="rounded-lg border border-[#ece8e3] px-1.5 py-2 sm:px-2.5 sm:py-3"
                                            >
                                                <p className="truncate text-[7px] text-[#8b857e] sm:text-[9px]">
                                                    {label}
                                                </p>
                                                <p className="mt-0.5 truncate text-[8px] font-semibold text-[#37333a] sm:text-[10px]">
                                                    {value}
                                                </p>
                                            </div>
                                        ))}
                                    </div>

                                    <div className="mt-3 flex items-center justify-between rounded-lg bg-[#17352f] px-3 py-2 text-white sm:mt-4 sm:px-4 sm:py-3">
                                        <span className="text-[8px] font-medium sm:text-[10px]">
                                            Review 5 drafts
                                        </span>
                                        <ArrowRight className="size-3" />
                                    </div>
                                </div>

                                <div className="hidden space-y-4 sm:block">
                                    <div className="rounded-2xl border border-[#ded9d2] bg-white p-4 shadow-sm">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-[10px] text-[#827c75]">
                                                    AI visibility
                                                </p>
                                                <p className="mt-0.5 text-2xl font-semibold tracking-[-0.05em] text-[#17352f]">
                                                    68
                                                    <span className="text-xs text-[#8f8982]">
                                                        /100
                                                    </span>
                                                </p>
                                            </div>
                                            <span className="flex items-center gap-1 rounded-full bg-[#ecf7ee] px-2 py-1 text-[9px] font-semibold text-[#2e7540]">
                                                <TrendingUp className="size-3" />
                                                14%
                                            </span>
                                        </div>
                                        <div className="mt-4 flex h-16 items-end gap-1.5">
                                            {[
                                                24, 31, 27, 43, 38, 52, 49, 64,
                                                71, 82,
                                            ].map((height, index) => (
                                                <span
                                                    key={index}
                                                    className={`flex-1 rounded-t-sm ${index > 6 ? 'bg-[#d6533c]' : 'bg-[#d7e0f6]'}`}
                                                    style={{
                                                        height: `${height}%`,
                                                    }}
                                                />
                                            ))}
                                        </div>
                                        <div className="mt-2 flex justify-between text-[8px] text-[#aaa49d]">
                                            <span>May</span>
                                            <span>Today</span>
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-[#ded9d2] bg-[#21453d] p-4 text-white shadow-sm">
                                        <div className="flex items-center justify-between">
                                            <p className="text-[10px] font-medium">
                                                Quality checks
                                            </p>
                                            <ShieldCheck className="size-4 text-[#f6d986]" />
                                        </div>
                                        <div className="mt-3 space-y-2.5">
                                            {[
                                                'Brand voice',
                                                'Source coverage',
                                                'Channel fit',
                                            ].map((label) => (
                                                <div
                                                    key={label}
                                                    className="flex items-center justify-between text-[9px]"
                                                >
                                                    <span className="text-white/65">
                                                        {label}
                                                    </span>
                                                    <CheckCircle2 className="size-3.5 text-[#78d08c]" />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="absolute -right-1 bottom-[17%] hidden w-48 rotate-3 rounded-2xl border border-[#ded9d2] bg-white p-3 shadow-[0_18px_50px_rgba(32,26,54,0.16)] lg:block">
                <div className="flex items-center gap-2">
                    <span className="flex size-7 items-center justify-center rounded-full bg-[#fff0eb] text-[#e15836]">
                        <Send className="size-3.5" />
                    </span>
                    <div>
                        <p className="text-[10px] font-semibold">Published</p>
                        <p className="text-[8px] text-[#8b857e]">
                            Blog + 2 channels
                        </p>
                    </div>
                    <Check className="ml-auto size-4 text-[#44a65b]" />
                </div>
            </div>

            <div className="absolute -bottom-6 -left-1 hidden w-52 -rotate-2 rounded-2xl border border-[#ded9d2] bg-white p-3.5 shadow-[0_18px_50px_rgba(32,26,54,0.14)] lg:block">
                <div className="flex items-center gap-2 text-[9px] font-semibold text-[#716b65]">
                    <CircleDot className="size-3.5 text-[#d6533c]" />
                    Feedback loop
                </div>
                <p className="mt-2 text-xs font-semibold text-[#252228]">
                    3 topics are gaining traction
                </p>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-[#eeeae5]">
                    <div className="h-full w-[72%] rounded-full bg-[#d6533c]" />
                </div>
            </div>
        </div>
    );
}

function ChannelStrip() {
    return (
        <section className="border-y border-[#d8cebd] bg-white py-7 sm:py-9">
            <div className="mx-auto flex max-w-7xl flex-col items-center gap-5 px-6 lg:flex-row lg:justify-between lg:px-8">
                <p className="shrink-0 text-[10px] font-semibold tracking-[0.16em] text-[#6a645e] uppercase">
                    One strategy, every place you need to show up
                </p>
                <div className="flex flex-wrap items-center justify-center gap-x-5 gap-y-3 sm:gap-x-8 lg:justify-end">
                    {channels.map((channel, index) => (
                        <span
                            key={channel}
                            className="flex items-center gap-2 text-xs font-semibold text-[#4c484e] sm:text-sm"
                        >
                            <span
                                className={`size-1.5 rounded-full ${index % 3 === 0 ? 'bg-[#d6533c]' : index % 3 === 1 ? 'bg-[#3155a5]' : 'bg-[#81a96b]'}`}
                            />
                            {channel}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}

function StrategyCard() {
    return (
        <div className="relative h-full overflow-hidden rounded-[28px] bg-[#f2d9d0] p-6 sm:p-8 lg:col-span-7 lg:min-h-[500px]">
            <div className="relative z-10 max-w-lg">
                <p className="text-[10px] font-semibold tracking-[0.15em] text-[#a13220] uppercase">
                    Living brand intelligence
                </p>
                <h3 className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-[#201d2d] sm:text-3xl">
                    It sounds like you because it starts with you
                </h3>
                <p className="mt-3 max-w-md text-sm leading-6 text-[#635d73] sm:text-base">
                    Avyo keeps your positioning, audience, voice, examples, and
                    guardrails in one editable source of truth—not buried in a
                    prompt history.
                </p>
            </div>

            <div className="relative z-10 mt-8 grid gap-3 rounded-2xl border border-white/70 bg-white/80 p-4 shadow-[0_20px_45px_rgba(74,53,158,0.12)] backdrop-blur sm:ml-auto sm:max-w-[480px] sm:p-5">
                <div className="flex items-center justify-between border-b border-[#ebe7f1] pb-3">
                    <div>
                        <p className="text-[10px] text-[#8a8493]">
                            Brand brief
                        </p>
                        <p className="text-sm font-semibold text-[#282430]">
                            Northstar · Version 6
                        </p>
                    </div>
                    <span className="rounded-full bg-[#e9f6ec] px-2.5 py-1 text-[9px] font-semibold text-[#2e7540]">
                        Active
                    </span>
                </div>
                {[
                    ['Positioning', 'The calm way to run a modern team'],
                    ['Audience', 'Founder-led service businesses'],
                    ['Voice', 'Clear, grounded, quietly confident'],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="grid gap-1 rounded-xl bg-[#f8f6fb] px-3 py-2.5 sm:grid-cols-[90px_1fr] sm:gap-3"
                    >
                        <span className="text-[9px] font-semibold tracking-wide text-[#8a8493] uppercase">
                            {label}
                        </span>
                        <span className="text-[11px] font-medium text-[#423c4b]">
                            {value}
                        </span>
                    </div>
                ))}
            </div>

            <div
                aria-hidden="true"
                className="absolute -right-20 -bottom-24 size-72 rounded-full border-[42px] border-white/28"
            />
        </div>
    );
}

function SocialCard() {
    return (
        <div className="relative h-full overflow-hidden rounded-[28px] bg-[#3155a5] p-6 text-white sm:p-8 lg:col-span-5 lg:min-h-[500px]">
            <p className="text-[10px] font-semibold tracking-[0.15em] text-white/80 uppercase">
                Social content studio
            </p>
            <h3 className="mt-2 max-w-md text-2xl font-semibold tracking-[-0.04em] sm:text-3xl">
                One idea, a native story for every channel
            </h3>
            <p className="mt-3 max-w-sm text-sm leading-6 text-white/75 sm:text-base">
                Generate a candidate pool, choose the strongest angle, and pair
                it with real or generated visuals before anything leaves Avyo.
            </p>

            <div className="relative mt-8 h-[200px]">
                <div className="absolute top-3 left-0 w-[54%] -rotate-3 rounded-2xl bg-[#21453d] p-4 shadow-xl">
                    <div className="flex items-center gap-2">
                        <span className="size-6 rounded-full bg-[#3155a5]" />
                        <span className="text-[9px] font-semibold">
                            Northstar
                        </span>
                    </div>
                    <p className="mt-4 text-[11px] leading-4 font-medium">
                        Your content calendar is not a strategy. The decisions
                        behind it are.
                    </p>
                    <div className="mt-4 flex gap-3 text-[8px] text-white/65">
                        <span>12 replies</span>
                        <span>38 saves</span>
                    </div>
                </div>
                <div className="absolute top-0 right-0 w-[51%] rotate-3 rounded-2xl border border-black/10 bg-[#fff8e9] p-3 text-[#232027] shadow-xl">
                    <div className="aspect-[4/3] rounded-xl bg-[#d7e0f6] p-3">
                        <div className="flex h-full flex-col justify-between rounded-lg border border-[#d6533c]/20 bg-white/55 p-3">
                            <span className="text-[9px] font-semibold tracking-[0.12em] text-[#a13220] uppercase">
                                Slide 01
                            </span>
                            <p className="text-[13px] leading-tight font-semibold tracking-[-0.04em]">
                                3 signals your topic is worth publishing
                            </p>
                        </div>
                    </div>
                    <p className="mt-2 text-[8px] font-semibold text-[#605a63]">
                        Carousel · 4 slides
                    </p>
                </div>
            </div>
        </div>
    );
}

function ApprovalCard() {
    return (
        <div className="rounded-[28px] border border-[#d8cebd] bg-white p-6 sm:p-8 lg:col-span-5">
            <div className="flex items-start justify-between gap-6">
                <div>
                    <p className="text-[10px] font-semibold tracking-[0.15em] text-[#4f8a45] uppercase">
                        Human review
                    </p>
                    <h3 className="mt-2 text-xl font-semibold tracking-[-0.035em] sm:text-2xl">
                        Automation with a visible human hand
                    </h3>
                    <p className="mt-3 text-sm leading-6 text-[#6f6962]">
                        Review content, sources, visuals, and channel fit from
                        one calm approval queue.
                    </p>
                </div>
            </div>
            <div className="mt-6 space-y-2.5">
                {[
                    ['Sources checked', 'Passed'],
                    ['Brand voice', '94 / 100'],
                    ['Visual selected', 'Real photo'],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-center justify-between rounded-xl bg-[#f8f2e8] px-3 py-2.5"
                    >
                        <span className="flex items-center gap-2 text-[11px] text-[#625d57]">
                            <CheckCircle2 className="size-3.5 text-[#4f9d5d]" />
                            {label}
                        </span>
                        <span className="text-[10px] font-semibold text-[#302d32]">
                            {value}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function FeedbackCard() {
    return (
        <div className="overflow-hidden rounded-[28px] bg-[#17352f] p-6 text-white sm:p-8 lg:col-span-7">
            <div className="grid gap-8 sm:grid-cols-[.8fr_1.2fr] sm:items-end">
                <div>
                    <p className="text-[10px] font-semibold tracking-[0.15em] text-white/65 uppercase">
                        Performance feedback
                    </p>
                    <h3 className="mt-2 text-xl font-semibold tracking-[-0.035em] sm:text-2xl">
                        The next cycle knows what the last one learned
                    </h3>
                    <p className="mt-3 text-sm leading-6 text-white/55">
                        Avyo closes the loop between publishing, visibility, and
                        planning instead of writing into the dark.
                    </p>
                </div>
                <div className="rounded-2xl border border-white/10 bg-white/[0.06] p-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-[9px] text-white/65">
                                Organic visibility
                            </p>
                            <p className="mt-1 text-xl font-semibold">+38.4%</p>
                        </div>
                        <span className="rounded-full bg-[#7bd98e]/15 px-2 py-1 text-[9px] font-semibold text-[#8de19e]">
                            Compounding
                        </span>
                    </div>
                    <div className="mt-5 flex h-20 items-end gap-1.5">
                        {[17, 25, 22, 34, 31, 39, 47, 51, 63, 72, 86].map(
                            (height, index) => (
                                <span
                                    key={index}
                                    className={`flex-1 rounded-t ${index > 7 ? 'bg-[#f1b94a]' : 'bg-white/12'}`}
                                    style={{ height: `${height}%` }}
                                />
                            ),
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Marketing() {
    return (
        <div className="marketing-page min-h-screen overflow-x-hidden bg-[var(--brand-canvas)] text-[var(--brand-ink)]">
            <Head title="Organic growth, orchestrated">
                <meta
                    head-key="description"
                    name="description"
                    content="Avyo turns your brand strategy into search content, social posts, and measurable organic growth—without adding a content team."
                />
            </Head>

            <header className="relative z-50 border-b border-[#d8cebd]/70 bg-[#f3ecdd]/90 backdrop-blur-xl">
                <div className="mx-auto flex h-[72px] max-w-7xl items-center justify-between px-5 sm:px-6 lg:px-8">
                    <a href="#top" aria-label="Avyo home" className="shrink-0">
                        <BrandMark />
                    </a>

                    <nav
                        aria-label="Primary navigation"
                        className="hidden items-center gap-7 md:flex"
                    >
                        <a
                            href="#product"
                            className="text-[13px] font-medium text-[#5f5a55] transition-colors hover:text-[#17352f]"
                        >
                            Product
                        </a>
                        <a
                            href="#how-it-works"
                            className="text-[13px] font-medium text-[#5f5a55] transition-colors hover:text-[#17352f]"
                        >
                            How it works
                        </a>
                        <a
                            href="#why-avyo"
                            className="text-[13px] font-medium text-[#5f5a55] transition-colors hover:text-[#17352f]"
                        >
                            Why Avyo
                        </a>
                        <a
                            href="#faq"
                            className="text-[13px] font-medium text-[#5f5a55] transition-colors hover:text-[#17352f]"
                        >
                            FAQ
                        </a>
                    </nav>

                    <div className="flex items-center gap-2.5">
                        <Link
                            href={login()}
                            className="hidden px-3 py-2 text-[13px] font-medium text-[#4e4944] transition-colors hover:text-[#17352f] sm:inline-flex"
                        >
                            Log in
                        </Link>
                        <a
                            href="#product"
                            className="group inline-flex h-10 items-center gap-2 rounded-full bg-[#17352f] px-4 text-[12px] font-semibold text-white shadow-sm transition-transform hover:-translate-y-0.5 sm:px-5"
                        >
                            See Avyo in action
                            <ArrowRight className="size-3.5 transition-transform group-hover:translate-x-0.5" />
                        </a>
                    </div>
                </div>
            </header>

            <main id="top">
                <section className="relative overflow-hidden pt-16 sm:pt-24 lg:pt-28">
                    <div
                        aria-hidden="true"
                        className="absolute top-[-8rem] left-1/2 h-[30rem] w-[52rem] -translate-x-1/2 rounded-full bg-[#d8e4f5] blur-[100px]"
                    />
                    <div
                        aria-hidden="true"
                        className="absolute top-48 -right-32 size-72 rounded-full bg-[#f3cf6a]/70 blur-[90px]"
                    />

                    <div className="relative mx-auto max-w-5xl px-5 text-center sm:px-6 lg:px-8">
                        <div className="inline-flex items-center rounded-full border border-[#d8cebd] bg-white/75 px-3.5 py-2 text-[10px] font-semibold tracking-[0.1em] text-[#615b55] uppercase shadow-sm backdrop-blur">
                            Your organic growth team, in one workspace
                        </div>

                        <h1 className="mx-auto mt-7 max-w-4xl text-[clamp(3.2rem,8vw,6.9rem)] leading-[0.88] font-semibold tracking-[-0.075em] text-balance">
                            One engine for
                            <span className="block font-serif font-normal tracking-[-0.055em] text-[#d6533c] italic">
                                staying visible
                            </span>
                        </h1>

                        <p className="mx-auto mt-7 max-w-2xl text-base leading-7 text-[#625d57] sm:mt-9 sm:text-lg sm:leading-8">
                            Avyo learns your brand, plans what to say, creates
                            for search and social, and gets smarter from real
                            performance. You approve the work. Avyo keeps the
                            momentum.
                        </p>

                        <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <a
                                href="#how-it-works"
                                className="group inline-flex h-12 w-full items-center justify-center rounded-full bg-[#17352f] px-6 text-sm font-semibold text-white shadow-[0_10px_24px_rgba(21,20,25,0.18)] transition-all hover:-translate-y-0.5 hover:shadow-[0_14px_30px_rgba(21,20,25,0.22)] sm:w-auto"
                            >
                                <ArrowLink>Explore the workflow</ArrowLink>
                            </a>
                            <Link
                                href={login()}
                                className="inline-flex h-12 w-full items-center justify-center rounded-full border border-[#d0c4b1] bg-white px-6 text-sm font-semibold text-[#332f2b] transition-colors hover:bg-[#f1eeea] sm:w-auto"
                            >
                                Open Avyo
                            </Link>
                        </div>

                        <div className="mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[11px] text-[#6f6962]">
                            <span className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#4f955d]" />
                                No prompt engineering
                            </span>
                            <span className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#4f955d]" />
                                Human approval built in
                            </span>
                            <span className="flex items-center gap-1.5">
                                <Check className="size-3.5 text-[#4f955d]" />
                                Multilingual from day one
                            </span>
                        </div>
                    </div>

                    <ProductPreview />
                    <div className="h-20 sm:h-28" />
                </section>

                <ChannelStrip />

                <section className="bg-[#17352f] py-20 text-white sm:py-28 lg:py-36">
                    <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                        <div className="grid gap-12 lg:grid-cols-[.9fr_1.1fr] lg:gap-20">
                            <div>
                                <p className="text-[10px] font-semibold tracking-[0.17em] text-[#f3cf6a] uppercase">
                                    The content treadmill ends here
                                </p>
                                <h2 className="mt-5 max-w-xl text-4xl leading-[1.02] font-semibold tracking-[-0.055em] text-balance sm:text-5xl lg:text-6xl">
                                    Your content should not be six disconnected
                                    jobs
                                </h2>
                            </div>
                            <div className="lg:pt-12">
                                <p className="max-w-xl text-lg leading-8 text-white/62">
                                    Research in one tool. Strategy in a
                                    document. Drafts in chat. Design somewhere
                                    else. Publishing by hand. Reporting too late
                                    to matter.
                                </p>
                                <p className="mt-5 max-w-xl text-lg leading-8 text-white/90">
                                    Avyo connects the entire loop, so every
                                    piece shares the same strategy and every
                                    result can make the next piece better.
                                </p>
                            </div>
                        </div>

                        <div className="mt-16 grid overflow-hidden rounded-2xl border border-white/10 sm:grid-cols-2 lg:mt-20 lg:grid-cols-4">
                            {[
                                [
                                    '01',
                                    'One living brief',
                                    'Your strategy stays editable and visible.',
                                ],
                                [
                                    '02',
                                    'One connected plan',
                                    'Search and social support the same story.',
                                ],
                                [
                                    '03',
                                    'One review queue',
                                    'Quality control without tab juggling.',
                                ],
                                [
                                    '04',
                                    'One feedback loop',
                                    'Performance changes what happens next.',
                                ],
                            ].map(([number, title, description], index) => (
                                <div
                                    key={number}
                                    className={`p-6 sm:p-7 ${index > 0 ? 'border-t border-white/10 sm:border-t-0 sm:border-l' : ''} ${index === 2 ? 'sm:border-t lg:border-t-0' : ''}`}
                                >
                                    <span className="text-[10px] font-semibold text-[#f3cf6a]">
                                        {number}
                                    </span>
                                    <h3 className="mt-8 text-base font-semibold">
                                        {title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-6 text-white/60">
                                        {description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section
                    id="how-it-works"
                    className="scroll-mt-20 bg-[var(--brand-canvas)] py-20 sm:py-28 lg:py-36"
                >
                    <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                        <div className="mx-auto max-w-3xl text-center">
                            <p className="text-[10px] font-semibold tracking-[0.17em] text-[#a13220] uppercase">
                                From URL to momentum
                            </p>
                            <h2 className="mt-5 text-4xl leading-[1.03] font-semibold tracking-[-0.055em] text-balance sm:text-5xl lg:text-6xl">
                                Set the direction once
                                <span className="block font-serif font-normal tracking-[-0.035em] text-[#817a73] italic">
                                    Keep moving every week
                                </span>
                            </h2>
                            <p className="mx-auto mt-6 max-w-2xl text-base leading-7 text-[#6a645e] sm:text-lg">
                                Avyo replaces the blank page with a repeatable
                                operating system built around your business.
                            </p>
                        </div>

                        <div className="mt-14 grid gap-4 lg:mt-20 lg:grid-cols-3">
                            {workflow.map((item) => (
                                <article
                                    key={item.number}
                                    className="group relative overflow-hidden rounded-[26px] border border-[#d8cebd] bg-white p-6 transition-transform duration-300 hover:-translate-y-1 sm:p-8"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="font-serif text-4xl leading-none text-[#d6533c] italic">
                                            {item.number}
                                        </span>
                                        <span className="h-px flex-1 bg-[#e4ded5]" />
                                    </div>
                                    <h3 className="mt-10 text-xl font-semibold tracking-[-0.035em] sm:text-2xl">
                                        {item.title}
                                    </h3>
                                    <p className="mt-3 min-h-[96px] text-sm leading-6 text-[#6c6660]">
                                        {item.description}
                                    </p>
                                    <div className="mt-7 flex items-center justify-between border-t border-[#ebe7e2] pt-5 text-[10px] font-semibold text-[#7c756f]">
                                        <span>{item.detail}</span>
                                        <ChevronRight className="size-3.5 transition-transform group-hover:translate-x-1" />
                                    </div>
                                </article>
                            ))}
                        </div>
                    </div>
                </section>

                <section
                    id="product"
                    className="scroll-mt-20 bg-white py-20 sm:py-28 lg:py-36"
                >
                    <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                        <div className="max-w-3xl">
                            <p className="text-[10px] font-semibold tracking-[0.17em] text-[#a13220] uppercase">
                                The Avyo workspace
                            </p>
                            <h2 className="mt-5 text-4xl leading-[1.03] font-semibold tracking-[-0.055em] text-balance sm:text-5xl lg:text-6xl">
                                Built for the whole system,
                                <span className="block font-serif font-normal tracking-[-0.035em] text-[#817a73] italic">
                                    not just the next draft
                                </span>
                            </h2>
                        </div>

                        <div className="mt-14 grid gap-4 lg:mt-20 lg:grid-cols-12">
                            <StrategyCard />
                            <SocialCard />
                            <ApprovalCard />
                            <FeedbackCard />
                        </div>
                    </div>
                </section>

                <section className="bg-[#f3ecdd] py-20 sm:py-28 lg:py-36">
                    <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                        <div className="grid gap-12 lg:grid-cols-[.72fr_1.28fr] lg:gap-20">
                            <div>
                                <p className="text-[10px] font-semibold tracking-[0.17em] text-[#a13220] uppercase">
                                    Everything connected
                                </p>
                                <h2 className="mt-5 text-4xl leading-[1.04] font-semibold tracking-[-0.055em] text-balance sm:text-5xl">
                                    The capabilities a compounding content
                                    system needs
                                </h2>
                                <p className="mt-6 max-w-md text-base leading-7 text-[#6b655f]">
                                    Every module shares the same brief, plan,
                                    and performance context. That connection is
                                    the product.
                                </p>
                            </div>

                            <div className="grid gap-x-10 gap-y-10 sm:grid-cols-2">
                                {engineFeatures.map((feature, index) => (
                                    <div key={feature.title}>
                                        <div className="flex items-center gap-3">
                                            <span className="font-serif text-xl text-[#a13220] italic">
                                                0{index + 1}
                                            </span>
                                            <span className="h-px flex-1 bg-[#d9d0c4]" />
                                        </div>
                                        <h3 className="mt-3 text-base font-semibold tracking-[-0.02em]">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-2 text-sm leading-6 text-[#6f6962]">
                                            {feature.description}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    id="why-avyo"
                    className="scroll-mt-20 bg-[#d6533c] py-20 text-[#151419] sm:py-28 lg:py-36"
                >
                    <div className="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                        <div className="grid gap-12 lg:grid-cols-[.95fr_1.05fr] lg:items-center lg:gap-20">
                            <div>
                                <p className="text-[10px] font-semibold tracking-[0.17em] uppercase">
                                    Why Avyo
                                </p>
                                <h2 className="mt-5 text-4xl leading-[1.02] font-semibold tracking-[-0.055em] text-balance sm:text-5xl lg:text-6xl">
                                    Most AI tools stop when the content exists
                                </h2>
                                <p className="mt-6 max-w-xl text-lg leading-8">
                                    Avyo keeps going until the work is reviewed,
                                    delivered, measured, and turned into a
                                    better next decision.
                                </p>
                            </div>

                            <div className="rounded-[28px] border border-white/20 bg-white/10 p-5 backdrop-blur sm:p-7">
                                <div className="rounded-2xl bg-[#10241f] p-5 sm:p-7">
                                    <p className="text-[10px] font-semibold tracking-[0.15em] text-white/65 uppercase">
                                        The Avyo loop
                                    </p>
                                    <div className="mt-6 space-y-2.5">
                                        {[
                                            [
                                                'Discover demand',
                                                'Market + performance',
                                            ],
                                            [
                                                'Plan the story',
                                                'One connected month',
                                            ],
                                            [
                                                'Create the work',
                                                'Search + social',
                                            ],
                                            [
                                                'Review the details',
                                                'Human in control',
                                            ],
                                            [
                                                'Learn what moved',
                                                'Signals back to strategy',
                                            ],
                                        ].map(([title, detail], index) => {
                                            return (
                                                <div
                                                    key={title}
                                                    className="flex items-center gap-3 rounded-xl border border-white/8 bg-white/[0.045] px-3 py-3"
                                                >
                                                    <span className="w-6 shrink-0 font-serif text-lg text-[#f6d986] italic">
                                                        0{index + 1}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block text-[11px] font-semibold text-white">
                                                            {title}
                                                        </span>
                                                        <span className="block truncate text-[9px] text-white/65">
                                                            {detail}
                                                        </span>
                                                    </span>
                                                    {index < 4 ? (
                                                        <ChevronRight className="size-3.5 text-white/25" />
                                                    ) : (
                                                        <RefreshCw className="size-3.5 text-[#8add9b]" />
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="mt-16 grid gap-px overflow-hidden rounded-2xl bg-white/16 sm:grid-cols-3 lg:mt-20">
                            {[
                                [
                                    'Strategy before volume',
                                    'Every piece has a reason to exist and a role in the wider plan.',
                                ],
                                [
                                    'Specificity before sameness',
                                    'Your real expertise, voice, products, and imagery stay in the work.',
                                ],
                                [
                                    'Learning before guessing',
                                    'Performance becomes an input, not a report nobody acts on.',
                                ],
                            ].map(([title, description]) => (
                                <div
                                    key={title}
                                    className="bg-[#d6533c] p-6 sm:p-8"
                                >
                                    <span className="block h-px w-10 bg-white/50" />
                                    <h3 className="mt-6 text-lg font-semibold tracking-[-0.025em]">
                                        {title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-6">
                                        {description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                <section
                    id="faq"
                    className="scroll-mt-20 bg-white py-20 sm:py-28 lg:py-36"
                >
                    <div className="mx-auto grid max-w-7xl gap-12 px-5 sm:px-6 lg:grid-cols-[.72fr_1.28fr] lg:gap-24 lg:px-8">
                        <div>
                            <p className="text-[10px] font-semibold tracking-[0.17em] text-[#a13220] uppercase">
                                Good questions
                            </p>
                            <h2 className="mt-5 text-4xl font-semibold tracking-[-0.055em] sm:text-5xl">
                                A little more clarity
                            </h2>
                            <p className="mt-5 max-w-sm text-base leading-7 text-[#6f6962]">
                                Avyo is built for teams that want consistent
                                organic growth without turning content
                                operations into their full-time job.
                            </p>
                        </div>

                        <div className="divide-y divide-[#e5e1dc] border-y border-[#e5e1dc]">
                            {faq.map((item, index) => (
                                <details
                                    key={item.question}
                                    className="group"
                                    open={index === 0}
                                >
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-6 py-5 text-base font-semibold tracking-[-0.02em] marker:content-none sm:py-6 sm:text-lg">
                                        {item.question}
                                        <span className="relative flex size-7 shrink-0 items-center justify-center rounded-full border border-[#dcd7d1]">
                                            <span className="absolute h-px w-3 bg-[#514c47]" />
                                            <span className="absolute h-3 w-px bg-[#514c47] transition-transform group-open:rotate-90 group-open:opacity-0" />
                                        </span>
                                    </summary>
                                    <p className="max-w-2xl pr-12 pb-6 text-sm leading-7 text-[#6c6660] sm:text-base">
                                        {item.answer}
                                    </p>
                                </details>
                            ))}
                        </div>
                    </div>
                </section>

                <section className="bg-white px-4 pb-4 sm:px-6 sm:pb-6">
                    <div className="relative mx-auto max-w-[1440px] overflow-hidden rounded-[28px] bg-[#17352f] px-5 py-20 text-center text-white sm:rounded-[36px] sm:px-8 sm:py-28 lg:py-36">
                        <div
                            aria-hidden="true"
                            className="absolute top-[-40%] left-1/2 h-[80%] w-[70%] -translate-x-1/2 rounded-full bg-[#d6533c]/35 blur-[100px]"
                        />
                        <div
                            aria-hidden="true"
                            className="absolute -right-20 -bottom-28 size-80 rounded-full border-[50px] border-[#3155a5]/15"
                        />
                        <div className="relative mx-auto max-w-4xl">
                            <p className="font-serif text-lg text-white/55 italic">
                                Strategy in, momentum out
                            </p>
                            <h2 className="mt-5 text-4xl leading-[1] font-semibold tracking-[-0.06em] text-balance sm:text-5xl lg:text-7xl">
                                Make your best ideas impossible to miss
                            </h2>
                            <p className="mx-auto mt-6 max-w-2xl text-base leading-7 text-white/58 sm:text-lg">
                                Build a consistent, connected presence across
                                search, AI answers, your site, and social—with
                                one system keeping the strategy together.
                            </p>
                            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                                <Link
                                    href={login()}
                                    className="group inline-flex h-12 items-center justify-center rounded-full bg-white px-6 text-sm font-semibold text-[#10241f] transition-transform hover:-translate-y-0.5"
                                >
                                    <ArrowLink>Open Avyo</ArrowLink>
                                </Link>
                                <a
                                    href="#top"
                                    className="inline-flex h-12 items-center justify-center rounded-full border border-white/18 bg-white/8 px-6 text-sm font-semibold text-white transition-colors hover:bg-white/12"
                                >
                                    Back to the top
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <footer className="bg-white px-5 py-10 sm:px-6 lg:px-8">
                <div className="mx-auto flex max-w-7xl flex-col gap-8 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <BrandMark />
                        <p className="mt-4 max-w-xs text-xs leading-5 text-[#7a746e]">
                            Organic growth, orchestrated for search, social, and
                            the way people discover brands now.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-x-6 gap-y-3 text-xs font-medium text-[#635e58]">
                        <a href="#product" className="hover:text-[#17352f]">
                            Product
                        </a>
                        <a
                            href="#how-it-works"
                            className="hover:text-[#17352f]"
                        >
                            How it works
                        </a>
                        <a href="#faq" className="hover:text-[#17352f]">
                            FAQ
                        </a>
                        <Link href={login()} className="hover:text-[#17352f]">
                            Log in
                        </Link>
                    </div>
                </div>
                <div className="mx-auto mt-8 flex max-w-7xl flex-col gap-2 border-t border-[#e5e1dc] pt-5 text-[10px] text-[#918b84] sm:flex-row sm:items-center sm:justify-between">
                    <span>© {new Date().getFullYear()} Avyo</span>
                    <span>Strategy in. Momentum out.</span>
                </div>
            </footer>
        </div>
    );
}
