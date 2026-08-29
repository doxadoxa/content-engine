/*
 * What is left when React has unmounted the page.
 *
 * By the time this renders, the component tree below the boundary is gone and
 * whatever the person was doing is not recoverable — so this offers the two
 * things that still work rather than pretending otherwise: reload, or go home.
 * No "try again" button, because there is nothing to retry; the boundary does
 * not re-render the tree that just threw.
 *
 * Deliberately plain, and deliberately not reading any application state. This
 * is the one component that has to render correctly when something upstream is
 * broken, so it depends on nothing but the palette — no layout, no provider,
 * no route helper, no store. It is also the one component that renders on both
 * sides of the shell (a break on the landing page and a break inside the
 * product both land here), which is why every colour carries a `shell-dark:`
 * variant as well as its light value.
 */
export default function ErrorFallback() {
    return (
        <div
            role="alert"
            className="flex min-h-screen items-center justify-center bg-[#f3ecdd] p-6 shell-dark:bg-[#10241f]"
        >
            <div className="w-full max-w-md rounded-2xl border border-[#d8cebd] bg-white p-6 text-center shadow-[0_18px_50px_rgba(23,53,47,0.18)] shell-dark:border-white/15 shell-dark:bg-[#17352f]">
                <h1 className="text-lg font-semibold tracking-[-0.03em] text-[#17352f] shell-dark:text-white">
                    This page stopped working
                </h1>

                <p className="mt-2 text-[13px] leading-6 text-[#6f6962] shell-dark:text-white/65">
                    Something broke on our side, not yours. The fault has been
                    reported and nothing you had saved is affected.
                </p>

                <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                    {/*
                        A full page load rather than an Inertia visit: the
                        router is part of what may have failed, and this has to
                        work without it.
                    */}
                    <button
                        type="button"
                        onClick={() => window.location.reload()}
                        className="inline-flex h-10 items-center justify-center rounded-full bg-[#17352f] px-5 text-[13px] font-semibold text-white transition-colors hover:bg-[#0f2621] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:bg-white shell-dark:text-[#10241f] shell-dark:hover:bg-white/90"
                    >
                        Reload the page
                    </button>

                    <a
                        href="/"
                        className="inline-flex h-10 items-center justify-center rounded-full border border-[#c9c2b8] px-5 text-[13px] font-semibold text-[#17352f] transition-colors hover:bg-[#f3ecdd] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:border-white/25 shell-dark:text-white shell-dark:hover:bg-white/10"
                    >
                        Go to the start
                    </a>
                </div>
            </div>
        </div>
    );
}
