import { Link } from '@inertiajs/react';
import { Facts, LegalPage, Section } from '@/components/legal-page';
import type {
    CookieRow,
    LegalEntity,
    Subprocessor,
} from '@/components/legal-page';
import { openPreferences } from '@/lib/consent';
import { cookies as cookiePolicy } from '@/routes/legal';

export default function Privacy({
    entity,
    updated,
    subprocessors,
}: {
    entity: LegalEntity;
    updated: string;
    subprocessors: Subprocessor[];
    cookies: CookieRow[];
}) {
    return (
        <LegalPage
            title="Privacy Policy"
            intro={`What ${entity.name} collects when you use ${entity.product}, why, who else sees it, and what you can ask us to do about it.`}
            updated={updated}
            entity={entity}
        >
            <section>
                <Facts
                    rows={[
                        ['Controller', entity.name],
                        [
                            'Registered office',
                            `${entity.address} (company number ${entity.companyNumber})`,
                        ],
                        [
                            'Data protection contact',
                            <a href={`mailto:${entity.email}`}>
                                {entity.email}
                            </a>,
                        ],
                        [
                            'Supervisory authority',
                            <a
                                href={entity.authority.url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {entity.authority.name}
                            </a>,
                        ],
                    ]}
                />
            </section>

            <Section id="roles" title="1. Which hat we are wearing">
                <p>
                    Two different things happen in {entity.product}, and data
                    protection law treats them differently.
                </p>
                <ul>
                    <li>
                        <strong>
                            Your account and how you use the Service.
                        </strong>{' '}
                        Here we are the <strong>controller</strong>: we decide
                        what to collect and why, and this policy is our
                        explanation of it.
                    </li>
                    <li>
                        <strong>
                            The material you put into your projects.
                        </strong>{' '}
                        Your brand brief, your site content, your drafts, and
                        the replies and mentions your connected social accounts
                        receive. Here we are a <strong>processor</strong> acting
                        on your instructions — you decide what goes in, and we
                        handle it to run the Service for you. If that material
                        contains other people's personal data, you are the
                        controller of it and responsible for having a lawful
                        basis to hold it.
                    </li>
                </ul>
            </Section>

            <Section id="collect" title="2. What we collect">
                <h3>You give us</h3>
                <ul>
                    <li>
                        <strong>Account details:</strong> your name and email
                        address, and a cryptographic hash of your password. We
                        never store the password itself.
                    </li>
                    <li>
                        <strong>Project material:</strong> the site addresses,
                        brand briefs, audiences, offers, tone, guardrails,
                        keywords, uploads, and instructions you supply.
                    </li>
                    <li>
                        <strong>Anything you type into the assistant,</strong>{' '}
                        which we keep so a conversation can be reopened.
                    </li>
                    <li>
                        <strong>Correspondence</strong> you send us.
                    </li>
                </ul>

                <h3>We record as you use it</h3>
                <ul>
                    <li>
                        <strong>Session records:</strong> IP address, browser
                        and device information, and the times of your requests.
                        These are how a session stays signed in and how we
                        investigate abuse.
                    </li>
                    <li>
                        <strong>Application and delivery logs:</strong> what the
                        Service did, what it refused to do, what it published,
                        and what a connected platform said back.
                    </li>
                    <li>
                        <strong>Usage metering:</strong> the model and image
                        calls made for your projects and their cost.
                    </li>
                </ul>

                <h3>Connected accounts bring in</h3>
                <ul>
                    <li>
                        <strong>Access tokens</strong> for the accounts you
                        connect, held so the Service can act as you authorised.
                    </li>
                    <li>
                        <strong>Performance data</strong> from Google Search
                        Console and Google Analytics for the property you grant
                        access to.
                    </li>
                    <li>
                        <strong>Social interactions:</strong> where you connect
                        a Threads account, the replies and mentions it receives
                        — including the author's display name, handle, the text
                        they wrote, and a link to the post. This is personal
                        data about people who are not our users; we hold it as
                        your processor so you can reply to them.
                    </li>
                </ul>

                <p>
                    We do not collect special category data, we do not use the
                    Service to make decisions about you by automated means that
                    produce legal effects, and we do not sell personal data to
                    anyone.
                </p>
            </Section>

            <Section id="why" title="3. Why we process it, and on what basis">
                <ul>
                    <li>
                        <strong>To provide the Service</strong> — running your
                        account, your projects, and everything the engine does
                        for them. Basis:{' '}
                        <strong>performance of a contract</strong> with you.
                    </li>
                    <li>
                        <strong>To keep the Service secure</strong> — detecting
                        abuse, rate-limiting, investigating incidents, keeping
                        logs. Basis: <strong>legitimate interests</strong> in
                        protecting the Service and its users.
                    </li>
                    <li>
                        <strong>To support and communicate with you</strong> —
                        answering you, and telling you about changes that affect
                        you. Basis: <strong>contract</strong> and{' '}
                        <strong>legitimate interests</strong>.
                    </li>
                    <li>
                        <strong>To improve the Service</strong> — understanding
                        which features work, from aggregated and internal
                        records. Basis: <strong>legitimate interests</strong>.
                    </li>
                    <li>
                        <strong>To meet legal obligations</strong> — accounting,
                        tax, and responding to lawful requests. Basis:{' '}
                        <strong>legal obligation</strong>.
                    </li>
                    <li>
                        <strong>Optional cookies</strong>, if any are ever set.
                        Basis: <strong>your consent</strong>, which you can
                        withdraw at any time.
                    </li>
                </ul>
                <p>
                    We do not use your project content to train our own models,
                    and we do not permit our providers to train their models on
                    it.
                </p>
            </Section>

            <Section id="sharing" title="4. Who else sees it">
                <p>
                    The Service is built out of other people's infrastructure,
                    so being specific matters more than reassuring language.
                    These are the categories of provider that receive data, and
                    what they receive:
                </p>

                <div className="mt-5 overflow-x-auto rounded-2xl border border-[#e5e1dc] bg-white">
                    <table className="w-full min-w-[34rem] border-collapse text-left text-[13px]">
                        <thead>
                            <tr className="border-b border-[#e5e1dc] bg-[#faf7f2]">
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Provider
                                </th>
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    What it is used for
                                </th>
                                <th className="px-4 py-3 font-semibold text-[#17352f]">
                                    Location
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {subprocessors.map((provider) => (
                                <tr
                                    key={provider.name}
                                    className="border-b border-[#f0ece6] align-top last:border-0"
                                >
                                    <td className="px-4 py-3 font-medium text-[#17352f]">
                                        {provider.name}
                                        {provider.optional && (
                                            <span className="mt-1 block text-[11px] font-normal text-[#918b84]">
                                                Only if you connect it
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 leading-6 text-[#4e4944]">
                                        {provider.purpose}
                                    </td>
                                    <td className="px-4 py-3 text-[#4e4944]">
                                        {provider.region}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="mt-5">
                    We also disclose data to professional advisers, and to
                    authorities where the law requires it. If the business is
                    ever sold or reorganised, data may transfer with it, and we
                    will tell you before that changes anything material about
                    this policy.
                </p>
            </Section>

            <Section id="transfers" title="5. Sending data outside the UK">
                <p>
                    Several providers above are in the United States. Where
                    personal data leaves the UK or the EEA, we rely on the UK
                    International Data Transfer Addendum to the European
                    Commission's Standard Contractual Clauses, or on the UK
                    extension to the EU–US Data Privacy Framework where the
                    provider is certified under it. You can ask us at{' '}
                    <a href={`mailto:${entity.email}`}>{entity.email}</a> which
                    applies to a particular provider.
                </p>
            </Section>

            <Section id="retention" title="6. How long we keep it">
                <ul>
                    <li>
                        <strong>Account details:</strong> while your account is
                        open, then deleted or anonymised within 90 days of
                        closure.
                    </li>
                    <li>
                        <strong>Project content and drafts:</strong> until you
                        delete them, or within 90 days of account closure.
                    </li>
                    <li>
                        <strong>Session records:</strong> a session expires
                        after 2 hours of inactivity.
                    </li>
                    <li>
                        <strong>
                            Application, delivery, and security logs:
                        </strong>{' '}
                        up to 12 months.
                    </li>
                    <li>
                        <strong>Access tokens:</strong> deleted as soon as you
                        disconnect the account.
                    </li>
                    <li>
                        <strong>Billing and accounting records:</strong> six
                        years, because tax law requires it.
                    </li>
                </ul>
                <p>
                    Backups are overwritten on a rolling cycle, so deleted data
                    can persist in a backup for a short period after it has gone
                    from the live system.
                </p>
            </Section>

            <Section id="rights" title="7. Your rights">
                <p>Under UK GDPR you can ask us to:</p>
                <ul>
                    <li>give you a copy of your personal data;</li>
                    <li>correct it if it is wrong;</li>
                    <li>
                        delete it, where we have no overriding reason to keep
                        it;
                    </li>
                    <li>restrict or object to how we use it;</li>
                    <li>
                        hand it to you or another provider in a portable format;
                    </li>
                    <li>
                        withdraw consent you gave, without affecting what we did
                        before you withdrew it.
                    </li>
                </ul>
                <p>
                    Write to{' '}
                    <a href={`mailto:${entity.email}`}>{entity.email}</a>. We
                    answer within one month, and we do not charge for it. If the
                    data is in a customer's project and we hold it as a
                    processor, we will point you to the customer who controls
                    it.
                </p>
                <p>
                    If you are unhappy with how we handled your data you can
                    complain to the {entity.authority.name} at{' '}
                    <a
                        href={entity.authority.url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {entity.authority.url}
                    </a>{' '}
                    or on {entity.authority.helpline}. We would rather you told
                    us first.
                </p>
            </Section>

            <Section id="security" title="8. Security">
                <p>
                    Access is restricted to those who need it. Traffic is
                    encrypted in transit, passwords are stored only as hashes,
                    connected-account tokens are encrypted at rest, and every
                    project is scoped so one customer's data cannot be read from
                    another's session. No system is perfectly secure; if a
                    breach puts your rights at risk we will tell you and the ICO
                    within the time the law allows.
                </p>
            </Section>

            <Section id="cookies" title="9. Cookies">
                <p>
                    {entity.product} sets only the cookies it needs to work and
                    to remember settings you chose yourself. It runs no tag
                    manager and no advertising pixel, and it builds no profile
                    of you. It does report its own errors, and — if you allow
                    analytics — time how quickly pages load; neither of those
                    sets a cookie. The{' '}
                    <Link href={cookiePolicy.url()}>cookie policy</Link> lists
                    every cookie by name, and you can{' '}
                    <button
                        type="button"
                        onClick={openPreferences}
                        className="font-medium text-[#17352f] underline underline-offset-2 hover:text-[#d6533c]"
                    >
                        change your choices
                    </button>{' '}
                    whenever you like.
                </p>
            </Section>

            <Section id="children" title="10. Children">
                <p>
                    The Service is for business use and is not intended for
                    anyone under 18. We do not knowingly collect data from
                    children. If you believe a child has given us personal data,
                    tell us and we will delete it.
                </p>
            </Section>

            <Section id="changes" title="11. Changes to this policy">
                <p>
                    We update this policy when what we do changes. The date at
                    the top always reflects the current version, and we notify
                    registered users by email before a material change takes
                    effect.
                </p>
            </Section>
        </LegalPage>
    );
}
