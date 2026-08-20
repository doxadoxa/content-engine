import { Link } from '@inertiajs/react';
import { CalendarCheck2, FileCheck2, Search, Send } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import AppearanceSwitch from '@/components/appearance-switch';
import { workspacePanelClass } from '@/components/workspace-page';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * The signed-out shell.
 *
 * The left panel repeats the landing page's promise, so arriving at the form
 * from an email link or a bookmark still explains what this is. On a phone it
 * collapses to the logo and the form.
 *
 * **On the product's own palette, which it was not.** The brand tokens exist
 * twice: `:root` carries an older near-black and violet set, and `.product-shell`
 * carries the Avyo system every signed-in screen actually uses — warm paper,
 * dark forest, editorial tomato. Because this layout sat outside that scope, its
 * `var(--brand-ink)` and `var(--brand-violet)` resolved to the older values, and
 * signing in moved a person between two colour schemes that had nothing in
 * common. It reads as arriving at a different product, which is the one thing a
 * sign-in screen must not do. Scoping the shell here is the whole fix; the panel
 * below and the pill on the submit button both come with it.
 *
 * **And it follows the account's appearance.** `.product-shell` has a `.dark`
 * variant, so the switch in the corner works on this screen exactly as it does
 * inside the product — which matters more here than anywhere, because this is
 * the one screen a person sees before any of their settings can be loaded.
 */
const PROMISE = [
    {
        icon: Search,
        text: 'Finds topics from your market and real performance',
    },
    { icon: CalendarCheck2, text: 'Plans a consistent multilingual calendar' },
    { icon: FileCheck2, text: 'Writes drafts with visible quality checks' },
    {
        icon: Send,
        text: 'Publishes approved work to verified channels',
    },
];

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="product-shell relative min-h-dvh bg-background lg:grid lg:grid-cols-2">
            {/*
             * Above both columns rather than inside either, so it sits in the
             * same place whether or not the promise panel is showing.
             */}
            <div className="absolute top-5 right-5 z-30">
                <AppearanceSwitch />
            </div>

            <div className="relative hidden h-full flex-col justify-between overflow-hidden bg-[var(--brand-ink)] p-10 text-[var(--brand-on-ink)] lg:flex">
                {/* A soft wash so the panel is not a flat rectangle. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-32 -left-20 size-96 rounded-full bg-[var(--brand-violet)]/25 blur-3xl"
                />

                <Link
                    href={home()}
                    className="relative z-20 flex items-center text-lg font-medium"
                >
                    {/* Forest panel in both schemes, so the mark is told which
                        surface it is on rather than following the theme. */}
                    <AppLogoIcon tone="cream" className="mr-2 size-8" />
                    Avyo
                </Link>

                <div className="relative z-20 max-w-sm">
                    <p className="text-2xl leading-snug font-semibold text-balance">
                        Research, write, review, and publish from one dependable
                        content workflow.
                    </p>

                    <ul className="mt-8 space-y-3">
                        {PROMISE.map((item) => (
                            <li
                                key={item.text}
                                className="flex items-center gap-3 text-sm text-[var(--brand-on-ink)]/70"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-on-ink)]/10">
                                    <item.icon
                                        className="size-3.5"
                                        strokeWidth={1.5}
                                    />
                                </span>
                                {item.text}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="relative z-20 text-xs text-[var(--brand-on-ink)]/70">
                    Your strategy stays visible. Nothing publishes unseen.
                </p>
            </div>

            <div className="flex w-full items-center justify-center px-6 py-14 lg:p-8">
                <div className="w-full sm:max-w-[26rem]">
                    <Link
                        href={home()}
                        className="mb-8 flex items-center justify-center gap-2 text-lg font-semibold lg:hidden"
                    >
                        <AppLogoIcon className="size-8" />
                        Avyo
                    </Link>

                    {/*
                     * The same panel the workspace is built from, imported
                     * rather than restated: this screen and the first screen
                     * behind it should be the same surface, and a copy of the
                     * class here would be the copy that drifts.
                     *
                     * Its radius is 1.5rem and the padding is 8, which puts the
                     * inputs inside on the system's own 1rem — concentric rather
                     * than a smaller square inside a rounder one.
                     */}
                    <div className={`${workspacePanelClass} p-8`}>
                        <div className="mb-7 flex flex-col gap-1.5">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-sm text-balance text-muted-foreground">
                                {description}
                            </p>
                        </div>

                        {children}
                    </div>

                    <p className="mt-6 text-center text-xs text-muted-foreground">
                        Accounts are created by your workspace owner.
                    </p>
                </div>
            </div>
        </div>
    );
}
