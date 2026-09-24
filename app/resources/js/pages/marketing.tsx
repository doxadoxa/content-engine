import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    FileCheck2,
    Search,
    TrendingUp,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import {
    ArticlePreview,
    CalendarPreview,
    DiscoveryPreview,
} from '@/components/marketing/product-preview';
import { openPreferences } from '@/lib/consent';
import { login } from '@/routes';
import { cookies, privacy, terms } from '@/routes/legal';

type PricingPlan = {
    key: string;
    name: string;
    price_cents: number;
    currency: string;
    limits: {
        articles: number | null;
        ai_answers: number;
        ai_questions: number;
        ai_frequency_days: number;
        improvements?: number | null;
        tracked_pages: number | null;
        locales: number | null;
        seats: number | null;
    };
};
type Props = {
    pricing: { currency: string; trial_days: number; plans: PricingPlan[] };
};

const workflow = [
    {
        number: '01',
        icon: Search,
        title: 'Reach people looking for you',
        text: 'Avyo researches the questions people ask when they need your services.',
    },
    {
        number: '02',
        icon: FileCheck2,
        title: 'Give them a reason to choose you',
        text: 'Helpful articles explain what you offer and guide visitors to the right service on your website.',
    },
    {
        number: '03',
        icon: TrendingUp,
        title: 'See what brings people in',
        text: 'Follow search traffic and AI mentions. Connect purchases to see which visits lead to sales.',
    },
];
const faq = [
    {
        question: 'How does Avyo help me attract customers?',
        answer: 'Avyo researches questions relevant to your services and publishes useful answers on your website. Those articles help people discover what you offer, understand it and find the right next step. You can follow search traffic and sampled AI mentions, and connect purchase tracking to measure sales. Results depend on demand, competition and your website; publication alone does not guarantee new customers.',
    },
    {
        question: 'Who is Avyo for?',
        answer: 'Local small and medium businesses that want customers to find and choose them through search engines and AI answers. Start with one website and the services that matter most to your business.',
    },
    {
        question: 'Does Avyo replace my website?',
        answer: 'Your website stays yours. Connect WordPress with the Avyo plugin or a compatible custom receiver. Avyo publishes articles there on your schedule; review-first mode is available.',
    },
    {
        question: 'Will Avyo guarantee Google rankings or AI recommendations?',
        answer: 'No. Search engines and AI systems make their own decisions. Avyo helps you make useful, evidence-based changes and shows the results that can actually be measured.',
    },
    {
        question: 'Can I review articles before publication?',
        answer: 'Yes. Automatic scheduled publication is the default for a new setup. Choose review first to approve articles yourself. You can adjust the calendar and pause individual publications.',
    },
    {
        question: 'What if purchases are not tracked yet?',
        answer: 'You can start creating and publishing useful content straight away. Connect search measurement and purchases when ready; your dashboard makes it clear which observations are available.',
    },
    {
        question: 'When do I add a payment card?',
        answer: 'You can create an account and prepare your project first. Starting the trial and launching work requires checkout with a payment method. The checkout shows the trial end date and recurring price before you confirm.',
    },
];
const buttonClass =
    'inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#17352f] px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#285046] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#17352f]';
function BrandMark() {
    return (
        <span className="inline-flex items-center gap-2.5">
            <AppLogoIcon className="size-8" />
            <span className="text-xl font-semibold tracking-[-0.04em]">
                Avyo
            </span>
        </span>
    );
}
export default function Marketing({ pricing }: Props) {
    return (
        <div className="marketing-page min-h-screen bg-[#f8f2e8] text-[#17352f]">
            <Head title="Get found. Win more customers.">
                <meta
                    head-key="description"
                    name="description"
                    content="Help more customers find and choose your business through Google and AI answers. Avyo handles the research, writing and publishing, while you follow website traffic and connected sales."
                />
            </Head>
            <header className="border-b border-[#d8cebd]">
                <div className="mx-auto flex min-h-20 max-w-6xl items-center justify-between gap-5 px-5 sm:px-8">
                    <a href="#top" aria-label="Avyo home">
                        <BrandMark />
                    </a>
                    <nav
                        aria-label="Primary navigation"
                        className="hidden items-center gap-6 text-sm md:flex"
                    >
                        <a href="#how-it-works" className="hover:underline">
                            How it works
                        </a>
                        <a href="#product" className="hover:underline">
                            Product
                        </a>
                        <a href="#pricing" className="hover:underline">
                            Pricing
                        </a>
                        <a href="#faq" className="hover:underline">
                            Questions
                        </a>
                    </nav>
                    <Link
                        href={login()}
                        className="rounded-full border border-[#b9b5a7] px-5 py-2.5 text-sm font-medium hover:bg-white/60"
                    >
                        Log in
                    </Link>
                </div>
            </header>
            <main id="top">
                <section className="mx-auto grid max-w-7xl items-center gap-16 px-5 pt-16 pb-24 sm:px-8 sm:pt-24 sm:pb-32 lg:grid-cols-[0.9fr_1.1fr] lg:gap-14 xl:gap-24">
                    <div className="max-w-xl">
                        <p className="text-xs font-semibold tracking-[0.15em] text-[#71675b] uppercase">
                            Grow your business through search &amp; AI
                        </p>
                        <h1 className="mt-7 text-[clamp(3rem,5.4vw,5.5rem)] leading-[1.02] font-semibold tracking-[-0.06em]">
                            Get found.
                            <br />
                            <span className="font-serif font-normal text-[#bc452f] italic">
                                Win more
                                <br /> customers.
                            </span>
                        </h1>
                        <p className="mt-7 max-w-md text-lg leading-8 text-[#625d57]">
                            Reach people looking for what you offer. Avyo helps
                            bring them to your website through Google and AI
                            answers, with content researched, written and
                            published for you.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center gap-5">
                            <Link href="/start" className={buttonClass}>
                                Get started{' '}
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                            <a
                                href="#how-it-works"
                                className="inline-flex min-h-12 items-center gap-2 text-sm font-medium underline decoration-[#b9b5a7] underline-offset-4"
                            >
                                See how it works
                            </a>
                        </div>
                        <p className="mt-5 text-xs leading-6 text-[#71675b]">
                            {pricing.trial_days}-day trial · Card required when
                            you launch
                        </p>
                        <div className="mt-10 flex flex-wrap gap-x-5 gap-y-3 border-t border-[#ded6c7] pt-6 text-xs text-[#625d57]">
                            <span className="flex items-center gap-2">
                                <Check
                                    className="size-3.5 text-[#55785c]"
                                    aria-hidden="true"
                                />{' '}
                                WordPress &amp; custom websites
                            </span>
                            <span className="flex items-center gap-2">
                                <Check
                                    className="size-3.5 text-[#55785c]"
                                    aria-hidden="true"
                                />{' '}
                                Automatic or review first
                            </span>
                        </div>
                    </div>
                    <CalendarPreview />
                </section>
                <section
                    id="how-it-works"
                    className="scroll-mt-6 bg-[#17352f] py-24 text-[#f8f2e8] sm:py-32"
                >
                    <div className="mx-auto max-w-6xl px-5 sm:px-8">
                        <p className="text-xs font-semibold tracking-[0.15em] text-[#f3cf6a] uppercase">
                            Be there when customers are looking
                        </p>
                        <h2 className="mt-5 max-w-2xl font-serif text-4xl leading-tight sm:text-5xl">
                            From finding you
                            <br />
                            to choosing you.
                        </h2>
                        <div className="mt-16 grid gap-12 md:grid-cols-3 lg:gap-20">
                            {workflow.map((step) => (
                                <article
                                    key={step.number}
                                    className="border-t border-white/20 pt-6"
                                >
                                    <div className="flex items-center justify-between text-[#f3cf6a]">
                                        <span className="text-xs">
                                            {step.number}
                                        </span>
                                        <step.icon
                                            className="size-6"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <h3 className="mt-7 text-xl font-semibold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-4 max-w-xs text-sm leading-7 text-[#d1d8d0]">
                                        {step.text}
                                    </p>
                                </article>
                            ))}
                        </div>
                    </div>
                </section>
                <section
                    id="product"
                    className="mx-auto max-w-6xl scroll-mt-8 px-5 py-24 sm:px-8 sm:py-36"
                >
                    <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-24">
                        <ArticlePreview />
                        <div className="max-w-md">
                            <p className="text-xs font-semibold tracking-[0.15em] text-[#a13220] uppercase">
                                Build confidence before the first conversation
                            </p>
                            <h2 className="mt-5 text-4xl leading-[1.12] font-semibold tracking-[-0.045em] sm:text-5xl">
                                Help visitors see
                                <br />
                                <span className="font-serif font-normal italic">
                                    why you’re the right choice.
                                </span>
                            </h2>
                            <p className="mt-7 text-base leading-8 text-[#625d57]">
                                Show customers how you can help. Answer their
                                questions, explain your services and give them a
                                clear next step toward booking or buying.
                            </p>
                            <ul className="mt-8 space-y-5 text-sm">
                                {[
                                    'Answer questions that shape buying decisions',
                                    'Build trust with accurate business information',
                                    'Guide interested visitors to your services',
                                ].map((text) => (
                                    <li
                                        key={text}
                                        className="flex items-start gap-3"
                                    >
                                        <Check
                                            className="mt-0.5 size-4 shrink-0 text-[#55785c]"
                                            aria-hidden="true"
                                        />
                                        {text}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                    <div className="mt-28 grid items-center gap-12 sm:mt-40 lg:grid-cols-2 lg:gap-24">
                        <div className="max-w-md">
                            <p className="text-xs font-semibold tracking-[0.15em] text-[#3155a5] uppercase">
                                Keep your attention on business results
                            </p>
                            <h2 className="mt-5 text-4xl leading-[1.12] font-semibold tracking-[-0.045em] sm:text-5xl">
                                See who finds you.
                                <br />
                                <span className="font-serif font-normal italic">
                                    Learn what leads to sales.
                                </span>
                            </h2>
                            <p className="mt-7 text-base leading-8 text-[#625d57]">
                                Follow visits from search, mentions in AI
                                answers and purchases when tracking is
                                connected. Use what you learn to focus on the
                                topics and services that matter to your
                                business.
                            </p>
                            <p className="mt-5 text-sm leading-7 text-[#625d57]">
                                Start with discovery and traffic. Add purchase
                                measurement when you’re ready.
                            </p>
                        </div>
                        <DiscoveryPreview />
                    </div>
                </section>
                <Pricing pricing={pricing} />
                <section
                    id="faq"
                    className="mx-auto max-w-3xl scroll-mt-6 px-5 py-24 sm:px-8 sm:py-36"
                >
                    <h2 className="mb-10 text-4xl font-semibold tracking-[-0.045em]">
                        A few practical questions
                    </h2>
                    {faq.map((item) => (
                        <details
                            key={item.question}
                            className="border-t border-[#d8cebd] py-7"
                        >
                            <summary className="cursor-pointer text-base font-semibold">
                                {item.question}
                            </summary>
                            <p className="mt-4 text-sm leading-7 text-[#625d57]">
                                {item.answer}
                            </p>
                        </details>
                    ))}
                </section>
                <section className="bg-[#e7dcc7] px-5 py-24 text-center sm:px-8 sm:py-32">
                    <h2 className="font-serif text-4xl">
                        Help your next customer find you.
                    </h2>
                    <p className="mx-auto mt-4 max-w-xl text-base leading-7 text-[#625d57]">
                        You focus on serving your customers. Avyo handles the
                        research, writing and publishing that help more people
                        discover your business.
                    </p>
                    <Link href="/start" className={`${buttonClass} mt-7`}>
                        Get started{' '}
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                </section>
            </main>
            <footer className="mx-auto max-w-6xl px-5 py-8 sm:px-8">
                <div className="flex flex-wrap items-center justify-between gap-6">
                    <BrandMark />
                    <div className="flex flex-wrap gap-5 text-xs text-[#625d57]">
                        <Link href={privacy()}>Privacy</Link>
                        <Link href={terms()}>Terms</Link>
                        <Link href={cookies()}>Cookies</Link>
                        <button
                            type="button"
                            onClick={openPreferences}
                            className="hover:underline"
                        >
                            Cookie settings
                        </button>
                    </div>
                </div>
                <p className="mt-6 border-t border-[#d8cebd] pt-5 text-xs leading-5 text-[#71675b]">
                    © {new Date().getFullYear()} Avyo · Courtly Ltd, registered
                    in England and Wales, company number 17009343
                </p>
            </footer>
        </div>
    );
}
function Pricing({ pricing }: Props) {
    const money = (cents: number, currency: string) =>
        new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency.toUpperCase(),
            maximumFractionDigits: 0,
        }).format(cents / 100);
    const limit = (value: number | null) =>
        value === null ? 'Unlimited' : String(value);
    const rows = (plan: PricingPlan) => [
        ['AI articles / month', limit(plan.limits.articles)],
        ...(plan.limits.improvements === undefined
            ? []
            : [
                  [
                      'Existing-page improvements / month',
                      limit(plan.limits.improvements),
                  ],
              ]),
        ['AI answer attempts / month', String(plan.limits.ai_answers)],
        ['Questions per AI check', String(plan.limits.ai_questions)],
        ['Tracked existing pages', limit(plan.limits.tracked_pages)],
        ['Languages', limit(plan.limits.locales)],
        ['Seats', limit(plan.limits.seats)],
    ];

    return (
        <section
            id="pricing"
            className="scroll-mt-6 bg-[#eee5d5] py-24 sm:py-32"
        >
            <div className="mx-auto max-w-6xl px-5 sm:px-8">
                <div className="mx-auto max-w-2xl text-center">
                    <p className="text-xs font-semibold tracking-[0.15em] text-[#a13220] uppercase">
                        Pricing
                    </p>
                    <h2 className="mt-5 text-4xl font-semibold tracking-[-0.045em]">
                        Choose your pace of growth
                    </h2>
                    <p className="mt-5 text-base leading-7 text-[#625d57]">
                        Try the workflow for {pricing.trial_days} days. Add a
                        card at checkout; see the trial end and recurring price
                        before you confirm.
                    </p>
                </div>
                <div
                    className={`mx-auto mt-12 grid max-w-4xl gap-6 ${pricing.plans.length > 1 ? 'sm:grid-cols-2' : 'max-w-lg'}`}
                >
                    {pricing.plans.map((plan) => (
                        <article
                            key={plan.key}
                            className="rounded-3xl border border-[#b9b5a7] bg-[#fffaf2] p-7 sm:p-9"
                        >
                            <p className="mb-3 text-xs font-semibold tracking-wider text-[#625d57] uppercase">
                                {plan.key === 'starter'
                                    ? 'Build a steady presence'
                                    : 'Reach more customer questions'}
                            </p>
                            <h3 className="text-xl font-semibold">
                                {plan.name}
                            </h3>
                            <p className="mt-4 text-4xl font-semibold tabular-nums">
                                {money(plan.price_cents, plan.currency)}
                                <span className="text-sm font-normal text-[#625d57]">
                                    {' '}
                                    / month
                                </span>
                            </p>
                            <p className="mt-7 flex items-baseline gap-2 border-y border-[#e3daca] py-5">
                                <strong className="text-3xl font-semibold">
                                    {limit(plan.limits.articles)}
                                </strong>
                                <span className="text-sm text-[#625d57]">
                                    articles per month
                                    <br />
                                    <span className="text-xs">
                                        {plan.key === 'starter'
                                            ? 'About 3 per week'
                                            : 'About one per day'}
                                    </span>
                                </span>
                            </p>
                            <ul className="mt-7 space-y-4 text-sm">
                                {[
                                    'Research and a content calendar',
                                    'Automatic publishing, with optional review',
                                    'WordPress and compatible custom websites',
                                    'Search traffic and connected purchase results',
                                    plan.limits.ai_frequency_days === 7
                                        ? 'Weekly AI visibility checks · 10 questions'
                                        : 'Monthly AI visibility check · 3 questions',
                                    ...(plan.limits.improvements
                                        ? [
                                              `${plan.limits.improvements} existing-page improvements per month`,
                                          ]
                                        : []),
                                ].map((text) => (
                                    <li
                                        key={text}
                                        className="flex items-start gap-3"
                                    >
                                        <Check
                                            className="mt-0.5 size-4 shrink-0 text-[#55785c]"
                                            aria-hidden="true"
                                        />
                                        {text}
                                    </li>
                                ))}
                            </ul>
                            <details className="mt-7 border-t border-[#e3daca] pt-5 text-sm">
                                <summary className="cursor-pointer font-medium">
                                    All plan details
                                </summary>
                                <ul className="mt-5 space-y-3">
                                    {rows(plan).map(([label, value]) => (
                                        <li
                                            key={label}
                                            className="flex justify-between gap-4 text-[#625d57]"
                                        >
                                            <span>{label}</span>
                                            <span className="font-medium text-[#17352f]">
                                                {value}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-5 text-xs leading-6 text-[#625d57]">
                                    Articles count once at first approval,
                                    including automatic approval. Revisions and
                                    publishing retries do not use another
                                    article. Allowances renew each billing
                                    month; weekly cadence is approximate. AI
                                    checks sample four services. Manual checks
                                    share the allowance; attempts that reach a
                                    provider count even if it fails. The trial
                                    includes three articles and one content
                                    plan. Search and customer results take
                                    longer to observe.
                                </p>
                            </details>
                            <Link
                                href={`/start?plan=${plan.key}`}
                                className={`${buttonClass} mt-7 w-full`}
                            >
                                Choose {plan.name}{' '}
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}
