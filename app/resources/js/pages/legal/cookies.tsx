import { Link } from '@inertiajs/react';
import { LegalPage, Section } from '@/components/legal-page';
import type { CookieRow, LegalEntity } from '@/components/legal-page';
import { openPreferences } from '@/lib/consent';
import { privacy } from '@/routes/legal';

/*
 * The table below is rendered from config/legal.php rather than typed out here,
 * so a cookie can only appear in the product by also appearing in the policy.
 * A hand-written cookie table is a document that starts true and becomes a
 * false statement about our processing the first time somebody ships a feature.
 */

const CATEGORY_LABELS: Record<string, string> = {
    essential: 'Strictly necessary',
    preferences: 'Preferences',
    analytics: 'Analytics',
    marketing: 'Marketing',
};

const CATEGORY_ORDER = ['essential', 'preferences', 'analytics', 'marketing'];

export default function Cookies({
    entity,
    updated,
    cookies,
}: {
    entity: LegalEntity;
    updated: string;
    cookies: CookieRow[];
}) {
    const optional = cookies.filter((cookie) =>
        ['analytics', 'marketing'].includes(cookie.category),
    );

    return (
        <LegalPage
            title="Cookie Policy"
            intro={`Every cookie ${entity.product} stores on your device, what each one is for, and how to change what you allow.`}
            updated={updated}
            entity={entity}
        >
            <Section id="summary" title="The short version">
                <p>
                    {entity.product} sets {cookies.length} cookies. All of them
                    are ours — no third party sets a cookie through this site.
                    None of them track you, build a profile of you, or follow
                    you to another website.
                </p>
                <p>
                    <strong>
                        There are currently no analytics, advertising, or
                        marketing cookies at all.
                    </strong>{' '}
                    We still ask, because the day we add one, consent for it
                    needs to have been collected beforehand rather than assumed
                    afterwards.
                </p>
                <p>
                    <button
                        type="button"
                        onClick={openPreferences}
                        className="inline-flex h-11 items-center justify-center rounded-full bg-[#17352f] px-5 text-[13px] font-semibold text-white transition-colors hover:bg-[#0f2621] focus-visible:ring-2 focus-visible:ring-[#17352f] focus-visible:ring-offset-2 focus-visible:outline-none"
                    >
                        Change your cookie settings
                    </button>
                </p>
            </Section>

            <Section id="what" title="What a cookie is">
                <p>
                    A cookie is a small text file a website asks your browser to
                    keep and send back on your next request. It is how a website
                    recognises that two requests came from the same browser —
                    which is what makes staying signed in possible at all.
                </p>
            </Section>

            <Section id="table" title="Every cookie we set">
                <div className="mt-2 overflow-x-auto rounded-2xl border border-[#e5e1dc] bg-white">
                    <table className="w-full min-w-[44rem] border-collapse text-left text-[13px]">
                        <thead>
                            <tr className="border-b border-[#e5e1dc] bg-[#faf7f2]">
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Name
                                </th>
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Category
                                </th>
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Purpose
                                </th>
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Expires
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...cookies]
                                .sort(
                                    (a, b) =>
                                        CATEGORY_ORDER.indexOf(a.category) -
                                        CATEGORY_ORDER.indexOf(b.category),
                                )
                                .map((cookie) => (
                                    <tr
                                        key={cookie.name}
                                        className="border-b border-[#f0ece6] align-top last:border-0"
                                    >
                                        <td className="px-4 py-3 font-mono text-[12px] font-medium text-[#17352f]">
                                            {cookie.name}
                                        </td>
                                        <td className="px-4 py-3 text-[#4e4944]">
                                            {CATEGORY_LABELS[cookie.category] ??
                                                cookie.category}
                                        </td>
                                        <td className="px-4 py-3 leading-6 text-[#4e4944]">
                                            {cookie.purpose}
                                        </td>
                                        <td className="px-4 py-3 text-[#4e4944]">
                                            {cookie.retention}
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
                {optional.length === 0 && (
                    <p className="mt-4 text-[13px] text-[#918b84]">
                        No analytics or marketing cookies are listed because
                        none are set. If that changes, they will appear in this
                        table and you will be asked again before any of them is
                        written.
                    </p>
                )}
            </Section>

            <Section
                id="categories"
                title="The categories, and which are a choice"
            >
                <h3>Strictly necessary — always on</h3>
                <p>
                    These make the product function: keeping you signed in,
                    stopping another website from submitting forms as you, and
                    remembering the cookie choice you made so you are not asked
                    on every page. They are exempt from consent under the
                    Privacy and Electronic Communications Regulations, and
                    switching them off would mean you could not sign in.
                </p>

                <h3>Preferences — always on</h3>
                <p>
                    Two cookies that remember a setting you changed yourself:
                    the theme, and whether the navigation column is collapsed.
                    They hold a single word each, contain no identifier, are
                    readable only by us, and cannot follow you anywhere. We
                    treat them as strictly necessary rather than offering a
                    switch, because a switch that silently kept writing them
                    would be worse than not offering one.
                </p>

                <h3>Analytics — your choice, currently unused</h3>
                <p>
                    Would tell us which pages are used and where people get
                    stuck. Nothing of the kind is installed today. If we add it,
                    it will not run until you say yes.
                </p>

                <h3>Marketing — your choice, currently unused</h3>
                <p>
                    Would tell us which campaigns bring people here. Nothing of
                    the kind is installed today, and the same rule applies.
                </p>
            </Section>

            <Section id="control" title="Changing your mind">
                <p>
                    Withdrawing permission is meant to be exactly as easy as
                    giving it. Use{' '}
                    <button
                        type="button"
                        onClick={openPreferences}
                        className="font-medium text-[#17352f] underline underline-offset-2 hover:text-[#d6533c]"
                    >
                        cookie settings
                    </button>
                    , which is also linked at the bottom of every page here.
                    Your answer is stored for 12 months, and we ask again after
                    that — or sooner if the list above changes, because consent
                    to an old list is not consent to a new one.
                </p>
                <p>
                    You can also clear or block cookies in your browser.
                    Blocking the strictly necessary ones will stop you being
                    able to sign in. Instructions are in the help pages for{' '}
                    <a
                        href="https://support.google.com/chrome/answer/95647"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Chrome
                    </a>
                    ,{' '}
                    <a
                        href="https://support.apple.com/en-gb/guide/safari/sfri11471/mac"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Safari
                    </a>
                    ,{' '}
                    <a
                        href="https://support.mozilla.org/en-US/kb/enhanced-tracking-protection-firefox-desktop"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Firefox
                    </a>
                    , and{' '}
                    <a
                        href="https://support.microsoft.com/en-gb/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Edge
                    </a>
                    .
                </p>
            </Section>

            <Section id="more" title="More on your data">
                <p>
                    Cookies are a small part of it. The{' '}
                    <Link href={privacy.url()}>privacy policy</Link> covers
                    everything else we hold, who we share it with, and the
                    rights you have over it. Questions go to{' '}
                    <a href={`mailto:${entity.email}`}>{entity.email}</a>.
                </p>
            </Section>
        </LegalPage>
    );
}
