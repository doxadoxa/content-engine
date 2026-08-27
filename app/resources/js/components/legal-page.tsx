import { Head, Link } from '@inertiajs/react';
import type { PropsWithChildren, ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { openPreferences } from '@/lib/consent';
import { login } from '@/routes';
import { cookies, privacy, terms } from '@/routes/legal';

/*
 * The frame the three public documents share: a way back to the product, the
 * date the text last changed, and — at the bottom of every one of them — a
 * button that reopens the cookie choice.
 *
 * That last button is the reason this is a component rather than three copies
 * of a page. Withdrawing consent has to be as easy as giving it, and "as easy"
 * in practice means it has to be somewhere a person can find without knowing
 * where to look. One footer, three pages, no page that forgot.
 */

export type LegalEntity = {
    name: string;
    companyNumber: string;
    address: string;
    jurisdiction: string;
    email: string;
    product: string;
    site: string;
    authority: { name: string; url: string; helpline: string };
};

export type CookieRow = {
    name: string;
    category: string;
    provider: string;
    purpose: string;
    retention: string;
};

export type Subprocessor = {
    name: string;
    purpose: string;
    region: string;
    optional: boolean;
};

/* Generated from the routes themselves (`@/routes/legal`), so a path that
 * changes in web.php changes here rather than 404-ing from a footer. */
const NAV = [
    { href: terms.url(), label: 'Terms' },
    { href: privacy.url(), label: 'Privacy' },
    { href: cookies.url(), label: 'Cookies' },
];

function formatDate(iso: string): string {
    const parsed = new Date(`${iso}T00:00:00Z`);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export function LegalPage({
    title,
    intro,
    updated,
    entity,
    children,
}: PropsWithChildren<{
    title: string;
    intro: string;
    updated: string;
    entity: LegalEntity;
}>) {
    return (
        <div className="marketing-page min-h-screen bg-[var(--brand-canvas)] text-[var(--brand-ink)]">
            <Head title={title}>
                <meta
                    head-key="description"
                    name="description"
                    content={intro}
                />
            </Head>

            <header className="border-b border-[#d8cebd]/70 bg-[#f3ecdd]/90 backdrop-blur-xl">
                <div className="mx-auto flex h-[72px] max-w-4xl items-center justify-between px-5 sm:px-6">
                    <Link href="/" aria-label="Avyo home" className="shrink-0">
                        <span className="inline-flex items-center gap-2.5">
                            <AppLogoIcon
                                tone="ink"
                                className="size-8 shrink-0"
                            />
                            <span className="text-[19px] font-semibold tracking-[-0.04em]">
                                Avyo
                            </span>
                        </span>
                    </Link>

                    <nav
                        aria-label="Legal documents"
                        className="flex items-center gap-5"
                    >
                        {NAV.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="text-[13px] font-medium text-[#5f5a55] transition-colors hover:text-[#17352f]"
                            >
                                {item.label}
                            </Link>
                        ))}
                        <Link
                            href={login()}
                            className="hidden text-[13px] font-medium text-[#4e4944] transition-colors hover:text-[#17352f] sm:inline"
                        >
                            Log in
                        </Link>
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-4xl px-5 py-14 sm:px-6 sm:py-20">
                <p className="text-[10px] font-semibold tracking-[0.17em] text-[#a13220] uppercase">
                    {entity.product} · Legal
                </p>
                <h1 className="mt-4 text-4xl font-semibold tracking-[-0.055em] text-balance sm:text-5xl">
                    {title}
                </h1>
                <p className="mt-5 max-w-2xl text-base leading-7 text-[#6f6962]">
                    {intro}
                </p>
                <p className="mt-6 text-[13px] text-[#918b84]">
                    Last updated {formatDate(updated)}
                </p>

                <div className="mt-12 space-y-10">{children}</div>
            </main>

            <footer className="border-t border-[#e5e1dc] bg-white px-5 py-10 sm:px-6">
                <div className="mx-auto flex max-w-4xl flex-col gap-6">
                    <div className="flex flex-wrap gap-x-6 gap-y-3 text-xs font-medium text-[#635e58]">
                        {NAV.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="hover:text-[#17352f]"
                            >
                                {item.label}
                            </Link>
                        ))}
                        <button
                            type="button"
                            onClick={openPreferences}
                            className="underline underline-offset-2 hover:text-[#17352f]"
                        >
                            Cookie settings
                        </button>
                    </div>
                    <p className="text-[11px] leading-5 text-[#918b84]">
                        {entity.name} · Registered in {entity.jurisdiction},
                        company number {entity.companyNumber} · {entity.address}
                    </p>
                </div>
            </footer>
        </div>
    );
}

/**
 * One numbered section. `id` is deliberately required: these documents get
 * linked to clause by clause, from support replies and from each other.
 */
export function Section({
    id,
    title,
    children,
}: PropsWithChildren<{ id: string; title: string }>) {
    return (
        <section id={id} className="scroll-mt-24">
            <h2 className="text-xl font-semibold tracking-[-0.03em] text-[#17352f] sm:text-2xl">
                {title}
            </h2>
            <Prose>{children}</Prose>
        </section>
    );
}

/*
 * Descendant selectors rather than a class on every tag, so the document bodies
 * below stay readable as prose — which is what they are, and what anyone
 * amending them will be reading them as.
 */
export function Prose({ children }: { children: ReactNode }) {
    return (
        <div className="mt-4 space-y-4 text-[15px] leading-7 text-[#4e4944] [&_a]:font-medium [&_a]:text-[#17352f] [&_a]:underline [&_a]:underline-offset-2 [&_a:hover]:text-[#d6533c] [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-[#17352f] [&_li]:pl-1 [&_strong]:font-semibold [&_strong]:text-[#17352f] [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5">
            {children}
        </div>
    );
}

/** A definition row for the entity block at the top of the terms and privacy. */
export function Facts({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="mt-4 divide-y divide-[#e5e1dc] overflow-hidden rounded-2xl border border-[#e5e1dc] bg-white">
            {rows.map(([label, value]) => (
                <div
                    key={label}
                    className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-6 sm:px-5"
                >
                    <dt className="w-48 shrink-0 text-[13px] font-semibold text-[#17352f]">
                        {label}
                    </dt>
                    <dd className="text-[14px] leading-6 text-[#4e4944]">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
