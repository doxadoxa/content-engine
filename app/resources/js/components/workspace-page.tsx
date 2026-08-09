import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type WorkspacePageProps = {
    children: ReactNode;
    className?: string;
    width?: 'reading' | 'wide';
};

/**
 * The dashboard's atmospheric frame, shared by primary workspace screens.
 * Content remains on the normal document layer; the glows are decorative and
 * intentionally ignore pointer and accessibility trees.
 */
export function WorkspacePage({
    children,
    className,
    width = 'wide',
}: WorkspacePageProps) {
    return (
        <div className="relative isolate min-h-full overflow-hidden">
            <div
                className="pointer-events-none absolute -top-40 right-0 -z-10 size-[32rem] rounded-full bg-violet-500/8 blur-3xl dark:bg-violet-500/5"
                aria-hidden="true"
            />
            <div
                className="pointer-events-none absolute top-[38rem] -left-52 -z-10 size-[28rem] rounded-full bg-orange-400/7 blur-3xl dark:bg-orange-400/4"
                aria-hidden="true"
            />

            <div
                className={cn(
                    'mx-auto flex w-full min-w-0 flex-col gap-5 p-4 sm:gap-6 sm:p-6 lg:p-8',
                    width === 'reading' ? 'max-w-[1280px]' : 'max-w-[1600px]',
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}

type WorkspaceHeaderProps = {
    eyebrow: string;
    context?: string;
    title: string;
    description?: string;
    actions?: ReactNode;
};

/** A consistent page hierarchy for operational screens. */
export function WorkspaceHeader({
    eyebrow,
    context,
    title,
    description,
    actions,
}: WorkspaceHeaderProps) {
    return (
        <header className="flex min-w-0 flex-col gap-5 border-b border-border/70 pb-6 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs font-medium tracking-[0.14em] text-muted-foreground uppercase">
                    <span>{eyebrow}</span>
                    {context && (
                        <>
                            <span className="size-1 rounded-full bg-violet-500" />
                            <span className="tracking-normal text-foreground/70 normal-case">
                                {context}
                            </span>
                        </>
                    )}
                </div>
                <h1 className="text-3xl font-semibold tracking-[-0.04em] break-words sm:text-4xl">
                    {title}
                </h1>
                {description && (
                    <p className="mt-2 max-w-3xl text-sm leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
                    {actions}
                </div>
            )}
        </header>
    );
}

/** Dashboard-aligned surface for substantial page sections. */
export const workspacePanelClass =
    'rounded-[1.5rem] border bg-card/85 shadow-[0_1px_2px_rgba(15,23,42,0.03),0_16px_44px_rgba(15,23,42,0.05)] backdrop-blur-sm';
