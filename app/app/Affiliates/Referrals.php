<?php

declare(strict_types=1);

namespace App\Affiliates;

use App\Affiliates\Jobs\SendPaymentToAnderro;
use App\Affiliates\Jobs\SendSignupToAnderro;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which sign-ups and payments a partner gets credit for, and therefore which
 * ones Anderro is told about at all.
 *
 * **Only people a partner actually referred.** Anderro's own guide sends every
 * sign-up and every payment and lets its side sort out which were referred.
 * That would hand a third party the address and the spending of every customer
 * we have, to credit the handful who clicked a partner's link — and the privacy
 * policy promises the opposite. So a sign-up is reported only when the browser
 * carries both of the script's cookies: the visitor id, which is what Anderro
 * links on, and the referral code, which exists only if that visitor arrived
 * through a partner's link. A payment is reported only for an account whose
 * sign-up was, and only while that account has not withdrawn the permission —
 * see {@see self::forgetIfWithdrawn()}.
 *
 * **Only with consent that is current.** The script is loaded behind the
 * `marketing` gate, so its cookies should not exist without that permission.
 * "Should not" is not good enough for a decision that sends an email address to
 * somebody else: cookies outlive the answer that allowed them — the consent
 * inventory is versioned, and a stale answer counts as none — so the consent
 * record is read here too, on the same terms resources/js/lib/consent.ts reads
 * it.
 *
 * **The cookies are read here, not posted by a form.** They are first-party
 * cookies on our domain, so the browser sends them on every request — the
 * sign-up form's POST and the return from Google alike. Reading them from the
 * request is one code path for every way in, rather than one per form plus a
 * cookie dance around each OAuth redirect, and a way in added later is covered
 * as long as it calls {@see self::signedUp()}.
 */
final class Referrals
{
    /** Written by Anderro's script. Asserted against the cookie policy in tests/Feature/Legal. */
    public const string VISITOR_COOKIE = '_anderro_vid';

    public const string REFERRAL_COOKIE = '_anderro_ref';

    /** Written by resources/js/lib/consent.ts. */
    public const string CONSENT_COOKIE = 'avyo_consent';

    public function __construct(private readonly Anderro $anderro) {}

    /**
     * An account was just made by somebody holding this request. Every way of
     * making one has to call this, or partners are not paid for the people who
     * arrive that way.
     */
    public function signedUp(User $user, Request $request): void
    {
        if (! $this->anderro->isConfigured()) {
            return;
        }

        $visitor = $this->referredVisitor($request);

        if ($visitor === null) {
            return;
        }

        // Marked now, not when Anderro confirms. A customer who skips the trial
        // can pay within the signup job's retry window, and a payment that
        // found the account unmarked would be dropped for good. The cost of the
        // other order is a payment reported for a signup Anderro never
        // recorded, which it cannot attribute and ignores.
        $user->forceFill(['affiliate_referred_at' => now()])->save();

        // After commit: the job reads the account back by id, and a worker
        // that picked it up first would find nobody.
        SendSignupToAnderro::dispatch((int) $user->getKey(), $visitor)->afterCommit();
    }

    /**
     * Stripe says an invoice was paid.
     *
     * Called with the invoice from `invoice.paid` only. Stripe also sends
     * `invoice.payment_succeeded` for the same money, and reporting both would
     * pay the partner twice.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function invoicePaid(array $invoice): void
    {
        if (! $this->anderro->isConfigured()) {
            return;
        }

        $amount = $this->commissionableCents($invoice);

        // A trial's zero invoice, or one settled entirely from credit. Anderro
        // refuses anything that is not above zero, and there is nothing to
        // earn a commission on anyway.
        if ($amount <= 0) {
            return;
        }

        $customer = $invoice['customer'] ?? null;

        if (! is_string($customer) || $customer === '') {
            return;
        }

        $user = User::query()->where('stripe_id', $customer)->first();

        if ($user === null || $user->affiliate_referred_at === null) {
            return;
        }

        SendPaymentToAnderro::dispatch((int) $user->getKey(), $amount)->afterCommit();
    }

    /**
     * Stop reporting this account's payments if its holder has said no.
     *
     * Consent is the basis for telling Anderro what a referred customer pays,
     * and withdrawing it has to stop that — not only the script in the browser.
     * The browser's answer lives in a cookie the server never sees change, so
     * it is read on the way past: switching marketing off reloads the page,
     * and the request that reload makes is where this runs. Only an explicit
     * refusal under the current inventory counts. No record at all, or one for
     * an older inventory, is somebody who has not been asked again yet.
     */
    public function forgetIfWithdrawn(User $user, Request $request): void
    {
        if ($user->affiliate_referred_at === null) {
            return;
        }

        $record = $this->consentRecord($request);

        if ($record !== null && ($record['marketing'] ?? null) !== true) {
            $user->forceFill(['affiliate_referred_at' => null])->save();
        }
    }

    /**
     * The visitor id to credit, or null when this request is not somebody a
     * partner referred with consent to track it.
     */
    public function referredVisitor(Request $request): ?string
    {
        if (! $this->allowsMarketing($request)) {
            return null;
        }

        $visitor = $request->cookie(self::VISITOR_COOKIE);
        $referral = $request->cookie(self::REFERRAL_COOKIE);

        // The script writes 32 hex characters. The bounds are looser than that
        // so a format change on their side does not silently stop crediting
        // anybody, and tight enough that a cookie somebody typed into their
        // browser is not forwarded to a third party as it stands.
        if (! is_string($visitor) || preg_match('/\A[A-Za-z0-9_-]{8,64}\z/', $visitor) !== 1) {
            return null;
        }

        if (! is_string($referral) || trim($referral) === '') {
            return null;
        }

        return $visitor;
    }

    /** Anything but an explicit `true` is a no. */
    private function allowsMarketing(Request $request): bool
    {
        return ($this->consentRecord($request)['marketing'] ?? null) === true;
    }

    /**
     * The consent record, read on the same terms the browser reads it: a record
     * for a different version of the cookie inventory is no record at all.
     *
     * @return array<string, mixed>|null
     */
    private function consentRecord(Request $request): ?array
    {
        $raw = $request->cookie(self::CONSENT_COOKIE);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $record = json_decode($raw, true);

        if (! is_array($record) || ($record['v'] ?? null) !== (string) config('legal.consent_version')) {
            return null;
        }

        return $record;
    }

    /**
     * What the customer actually paid us, before tax.
     *
     * `amount_paid` is what left their card — which already excludes any credit
     * balance applied — but it includes VAT, and tax collected on the
     * government's behalf is not revenue a partner earns a share of. So the
     * smaller of the two: the pre-tax total, unless less than that was paid.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function commissionableCents(array $invoice): int
    {
        $paid = $invoice['amount_paid'] ?? null;

        if (! is_int($paid)) {
            return 0;
        }

        $beforeTax = $invoice['total_excluding_tax'] ?? null;

        return is_int($beforeTax) ? min($paid, $beforeTax) : $paid;
    }
}
