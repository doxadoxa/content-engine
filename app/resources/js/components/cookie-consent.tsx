import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    acceptAll,
    currentConsent,
    getSnapshot,
    onOpenPreferences,
    rejectAll,
    saveConsent,
    subscribe,
} from '@/lib/consent';
import type { Consent, OptionalCategory } from '@/lib/consent';
import { cookies as cookiePolicy, privacy } from '@/routes/legal';

/*
 * The banner, and the panel behind its third button.
 *
 * Mounted once for the whole application (see app.tsx) rather than per layout,
 * because consent belongs to the visitor and not to the screen: somebody who
 * answers it on the landing page should not be asked again on the login form.
 *
 * Three buttons, and the two that decide something are the same size, weight
 * and prominence. That symmetry is the entire legal point of the component —
 * "Accept all" styled as a button and "Essential only" styled as a grey link is
 * the dark pattern ICO names first, and it invalidates the consent it collects.
 */

/*
 * Two shapes, not one with a `locked` flag over a wide key union. The first
 * version of this typed `key` as "either a consent category or one of the two
 * locked names", which let `onChange` write `essential: false` into the record
 * that gets serialised to the cookie. Nothing triggered it — the inputs are
 * disabled — but the type permitted a consent record with keys the store does
 * not understand, and that is the sort of thing that stops being theoretical
 * the moment somebody makes the locked rows collapsible.
 */
type LockedToggle = {
    locked: true;
    key: 'essential' | 'preferences';
    title: string;
    description: string;
};

type ChoiceToggle = {
    locked: false;
    key: OptionalCategory;
    title: string;
    description: string;
};

type Toggle = LockedToggle | ChoiceToggle;

const TOGGLES: Toggle[] = [
    {
        locked: true,
        key: 'essential',
        title: 'Strictly necessary',
        description:
            'Keeps you signed in, protects forms from cross-site submission, and remembers this choice. Without them the product does not work, so they cannot be turned off.',
    },
    {
        locked: true,
        key: 'preferences',
        title: 'Preferences',
        description:
            'Remembers a setting you changed yourself, such as the theme or a collapsed sidebar. No identifier, no tracking, and only ever written when you change the setting.',
    },
    {
        locked: false,
        key: 'analytics',
        title: 'Analytics',
        description:
            'Measures how quickly pages and actions load, so we can find what is slow. No cookie and no advertising profile — and nothing is measured until you allow it here. Errors are always reported, with or without this, because a page that breaks is our defect rather than a measurement of you.',
    },
    {
        locked: false,
        key: 'marketing',
        title: 'Marketing',
        description:
            'Would measure which campaigns bring people here. Avyo sets no advertising or marketing cookies today, and none will be set unless you allow it.',
    },
];

export default function CookieConsent() {
    const { decided } = useSyncExternalStore(
        subscribe,
        getSnapshot,
        getSnapshot,
    );

    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<Consent>(currentConsent);

    /*
     * Re-read on open rather than on mount. Somebody reopening this from the
     * cookie policy is coming back to change an answer, and the panel has to
     * show the answer they actually gave, not the defaults it was built with.
     */
    const openPanel = useCallback(() => {
        setDraft(currentConsent());
        setOpen(true);
    }, []);

    useEffect(() => onOpenPreferences(openPanel), [openPanel]);

    return (
        <>
            {!decided && !open && (
                <div
                    role="region"
                    aria-label="Cookie choices"
                    className="fixed inset-x-0 bottom-0 z-100 p-3 sm:p-4"
                >
                    <div className="mx-auto flex max-w-4xl flex-col gap-4 rounded-2xl border border-[#d8cebd] bg-white p-4 shadow-[0_18px_50px_rgba(23,53,47,0.18)] sm:flex-row sm:items-center sm:gap-6 sm:p-5 shell-dark:border-white/15 shell-dark:bg-[#10241f]">
                        <p className="text-[13px] leading-6 text-[#4e4944] shell-dark:text-white/75">
                            Avyo uses only the cookies it needs to sign you in
                            and remember your settings. Nothing measures or
                            tracks you unless you allow it.{' '}
                            <Link
                                href={cookiePolicy.url()}
                                className="font-semibold text-[#17352f] underline underline-offset-2 hover:text-[#d6533c] shell-dark:text-white shell-dark:hover:text-[#f3cf6a]"
                            >
                                Cookie policy
                            </Link>
                        </p>

                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={openPanel}
                                className="inline-flex h-10 items-center justify-center rounded-full px-4 text-[13px] font-semibold text-[#4e4944] underline underline-offset-4 hover:text-[#17352f] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:text-white/70 shell-dark:hover:text-white"
                            >
                                Choose
                            </button>
                            <button
                                type="button"
                                onClick={rejectAll}
                                className="inline-flex h-10 items-center justify-center rounded-full border border-[#c9c2b8] px-5 text-[13px] font-semibold text-[#17352f] transition-colors hover:bg-[#f3ecdd] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:border-white/25 shell-dark:text-white shell-dark:hover:bg-white/10"
                            >
                                Essential only
                            </button>
                            <button
                                type="button"
                                onClick={acceptAll}
                                className="inline-flex h-10 items-center justify-center rounded-full bg-[#17352f] px-5 text-[13px] font-semibold text-white transition-colors hover:bg-[#0f2621] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:bg-white shell-dark:text-[#10241f] shell-dark:hover:bg-white/90"
                            >
                                Accept all
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/*
                Radix rather than a hand-rolled overlay, and not for the
                animation: it traps Tab inside the panel, returns focus to
                whatever opened it, locks the page behind it, and closes on
                Escape and on a click outside. A consent panel a keyboard user
                can tab out of — into a page they cannot see the state of — is
                not a working way to exercise a right.

                Closing without choosing changes nothing, deliberately. An
                unanswered banner comes back; a dismissal recorded as a decision
                would be consent nobody gave.
            */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg border-[#d8cebd] bg-white text-[#4e4944] shell-dark:border-white/15 shell-dark:bg-[#10241f] shell-dark:text-white/70">
                    <DialogHeader>
                        <DialogTitle className="text-lg font-semibold tracking-[-0.03em] text-[#17352f] shell-dark:text-white">
                            Cookie preferences
                        </DialogTitle>
                        <DialogDescription className="text-[13px] leading-6 text-[#6f6962] shell-dark:text-white/60">
                            Two of these are part of how the product works and
                            cannot be switched off. The other two are yours to
                            decide, and neither sets a cookie.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        {TOGGLES.map((toggle) => (
                            <div
                                key={toggle.key}
                                className="rounded-xl border border-[#e5e1dc] p-3.5 shell-dark:border-white/10"
                            >
                                <label className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={
                                            toggle.locked
                                                ? true
                                                : draft[toggle.key]
                                        }
                                        disabled={toggle.locked}
                                        onChange={(event) => {
                                            if (toggle.locked) {
                                                return;
                                            }

                                            setDraft({
                                                ...draft,
                                                [toggle.key]:
                                                    event.target.checked,
                                            });
                                        }}
                                        className="mt-0.5 size-4 shrink-0 accent-[#17352f] disabled:opacity-60 shell-dark:accent-white"
                                    />
                                    <span>
                                        <span className="flex items-center gap-2 text-[13px] font-semibold text-[#17352f] shell-dark:text-white">
                                            {toggle.title}
                                            {toggle.locked && (
                                                <span className="rounded-full bg-[#f3ecdd] px-2 py-0.5 text-[10px] font-semibold text-[#6f6962] shell-dark:bg-white/10 shell-dark:text-white/60">
                                                    Always on
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-1 block text-[12px] leading-5 text-[#6f6962] shell-dark:text-white/60">
                                            {toggle.description}
                                        </span>
                                    </span>
                                </label>
                            </div>
                        ))}
                    </div>

                    <p className="text-[12px] leading-5 text-[#6f6962] shell-dark:text-white/55">
                        Full detail is in the{' '}
                        <Link
                            href={cookiePolicy.url()}
                            className="font-semibold text-[#17352f] underline underline-offset-2 shell-dark:text-white"
                        >
                            cookie policy
                        </Link>{' '}
                        and the{' '}
                        <Link
                            href={privacy.url()}
                            className="font-semibold text-[#17352f] underline underline-offset-2 shell-dark:text-white"
                        >
                            privacy policy
                        </Link>
                        .
                    </p>

                    <DialogFooter>
                        <button
                            type="button"
                            onClick={() => {
                                saveConsent(draft);
                                setOpen(false);
                            }}
                            className="inline-flex h-10 items-center justify-center rounded-full border border-[#c9c2b8] px-5 text-[13px] font-semibold text-[#17352f] transition-colors hover:bg-[#f3ecdd] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:border-white/25 shell-dark:text-white shell-dark:hover:bg-white/10"
                        >
                            Save choices
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                acceptAll();
                                setOpen(false);
                            }}
                            className="inline-flex h-10 items-center justify-center rounded-full bg-[#17352f] px-5 text-[13px] font-semibold text-white transition-colors hover:bg-[#0f2621] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none shell-dark:bg-white shell-dark:text-[#10241f] shell-dark:hover:bg-white/90"
                        >
                            Accept all
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
