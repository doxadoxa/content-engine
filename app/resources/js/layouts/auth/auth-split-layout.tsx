import { Link } from '@inertiajs/react';
import { CalendarCheck2, FileCheck2, Search, Send } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * The signed-out shell.
 *
 * The left panel repeats the landing page's promise, so arriving at the form
 * from an email link or a bookmark still explains what this is. On a phone it
 * collapses to the logo and the form.
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
        <div className="relative grid min-h-dvh flex-col items-center justify-center px-6 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div className="relative hidden h-full flex-col justify-between overflow-hidden bg-[var(--brand-ink)] p-10 text-white lg:flex">
                {/* A soft wash so the panel is not a flat black rectangle. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-32 -left-20 size-96 rounded-full bg-[var(--brand-violet)]/25 blur-3xl"
                />

                <Link
                    href={home()}
                    className="relative z-20 flex items-center text-lg font-medium"
                >
                    <AppLogoIcon className="mr-2 size-8 fill-current text-white" />
                    Content Engine
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
                                className="flex items-center gap-3 text-sm text-white/70"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-white/10">
                                    <item.icon className="size-3.5" />
                                </span>
                                {item.text}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="relative z-20 text-xs text-white/70">
                    Your strategy stays visible. Nothing publishes unseen.
                </p>
            </div>

            <div className="w-full py-12 lg:p-8">
                <div className="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <Link
                        href={home()}
                        className="relative z-20 flex items-center justify-center gap-2 text-lg font-semibold lg:hidden"
                    >
                        <span className="flex size-8 items-center justify-center rounded-md bg-primary">
                            <AppLogoIcon className="size-5 fill-current text-primary-foreground" />
                        </span>
                        Content Engine
                    </Link>

                    <div className="flex flex-col items-start gap-2 text-left sm:items-center sm:text-center">
                        <h1 className="text-xl font-medium">{title}</h1>
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    </div>

                    {children}
                </div>
            </div>
        </div>
    );
}
