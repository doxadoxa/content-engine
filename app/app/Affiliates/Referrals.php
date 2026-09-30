<?php

declare(strict_types=1);

namespace App\Affiliates;

use App\Affiliates\Jobs\SendPaymentToAnderro;
use App\Affiliates\Jobs\SendSignupToAnderro;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

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
 * through a partner's link. A payment is reported only for an account with an
 * {@see AffiliateReferral}.
 *
 * **Only with consent that is current.** The script is loaded behind the
 * `marketing` gate, so its cookies should not exist without that permission.
 * "Should not" is not good enough for a decision that sends an email address to
 * somebody else: cookies outlive the answer that allowed them — the consent
 * inventory is versioned, and a stale answer counts as none — so the consent
 * record is read here too, on the same terms resources/js/lib/consent.ts reads
 * it. And it is *kept*, on the referral, because payments arrive from Stripe
 * with no browser attached: a renewal a year from now has to be judged against
 * the consent as it stands then, not as it stood at sign-up. See
 * {@see AffiliateReferral::mayReport()}, which every report — and every queued
 * report, again, when it runs — has to pass.
 *
 * **Withdrawal reaches the account whether or not anybody is signed in.** A
 * refusal from the browser the sign-up came from is matched by its visitor id
 * ({@see self::withdrawVisitor()}); a refusal from anywhere the customer is
 * signed in is matched by the account ({@see self::syncConsent()}).
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

    /**
     * The script writes 32 hex characters. The bounds are looser than that so a
     * format change on their side does not silently stop crediting anybody, and
     * tight enough that a cookie somebody typed into their browser is not
     * forwarded to a third party as it stands.
     */
    public const string VISITOR_PATTERN = '/\A[A-Za-z0-9_-]{8,64}\z/';

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
        $record = $this->consentRecord($request);

        if ($visitor === null || $record === null) {
            return;
        }

        // Recorded now, not when Anderro confirms. A customer who skips the
        // trial can pay within the signup job's retry window, and a payment
        // that found no referral would be dropped for good. The cost of the
        // other order is a payment reported for a signup Anderro never
        // recorded, which it cannot attribute and ignores.
        AffiliateReferral::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'visitor_id' => $visitor,
                'email' => $user->email,
                'consent_version' => (string) config('legal.consent_version'),
                'consented_at' => $this->givenAt($record),
            ],
        );

        // After commit: the job reads the account back by id, and a worker
        // that picked it up first would find nobody.
        SendSignupToAnderro::dispatch((int) $user->getKey())->afterCommit();
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

        if ($user?->affiliateReferral?->mayReport() !== true) {
            return;
        }

        SendPaymentToAnderro::dispatch((int) $user->getKey(), $amount)->afterCommit();
    }

    /**
     * Bring a signed-in customer's referral into line with the answer their
     * browser now carries.
     *
     * A refusal stops the reporting. A fresh yes — a new inventory accepted, or
     * the twelve months renewed — resumes it, because it is the same permission
     * given again for the same purpose. No record, or one for an older
     * inventory, changes nothing: that is somebody who has not been asked again
     * yet, and {@see AffiliateReferral::mayReport()} already stops reporting on
     * a consent that has lapsed.
     */
    public function syncConsent(User $user, Request $request): void
    {
        $record = $this->consentRecord($request);

        if ($record === null) {
            return;
        }

        $referral = $user->affiliateReferral;

        if ($referral === null) {
            return;
        }

        if (($record['marketing'] ?? null) !== true) {
            $this->revoke($referral);

            return;
        }

        $givenAt = $this->givenAt($record);

        if (
            $referral->consent_version !== (string) config('legal.consent_version')
            || $referral->consented_at === null
            || $givenAt->greaterThan($referral->consented_at)
        ) {
            $referral->forceFill([
                'consent_version' => (string) config('legal.consent_version'),
                'consented_at' => $givenAt,
            ])->save();
        }
    }

    /**
     * The browser a referral was reported from has withdrawn marketing consent,
     * and may not be signed in to say whose account that was.
     *
     * Only acts on a current refusal the request itself carries, so the endpoint
     * in front of this cannot be used to switch off somebody's reporting on
     * their behalf by a browser that has not refused anything.
     */
    public function withdrawVisitor(Request $request): void
    {
        $record = $this->consentRecord($request);

        if ($record === null || ($record['marketing'] ?? null) === true) {
            return;
        }

        $visitor = $this->visitorIn($request);

        if ($visitor === null) {
            return;
        }

        AffiliateReferral::query()
            ->where('visitor_id', $visitor)
            ->get()
            ->each(fn (AffiliateReferral $referral) => $this->revoke($referral));
    }

    /**
     * The visitor id to credit, or null when this request is not somebody a
     * partner referred with consent to track it.
     */
    public function referredVisitor(Request $request): ?string
    {
        if (($this->consentRecord($request)['marketing'] ?? null) !== true) {
            return null;
        }

        $referral = $request->cookie(self::REFERRAL_COOKIE);

        if (! is_string($referral) || trim($referral) === '') {
            return null;
        }

        return $this->visitorIn($request);
    }

    private function revoke(AffiliateReferral $referral): void
    {
        if ($referral->consent_version === null && $referral->consented_at === null) {
            return;
        }

        $referral->forceFill(['consent_version' => null, 'consented_at' => null])->save();
    }

    /** The visitor id the script wrote, if it looks like one. See {@see self::VISITOR_PATTERN}. */
    private function visitorIn(Request $request): ?string
    {
        $visitor = $request->cookie(self::VISITOR_COOKIE);

        return is_string($visitor) && preg_match(self::VISITOR_PATTERN, $visitor) === 1
            ? $visitor
            : null;
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
     * When the answer was given, which is when its twelve months started. The
     * record's own timestamp, because the browser forgets the answer a year
     * after *that* and the server must not outlast it; never later than now,
     * because the timestamp is written by the browser and a clock set forward
     * would otherwise buy somebody else's consent a longer life.
     *
     * @param  array<string, mixed>  $record
     */
    private function givenAt(array $record): Carbon
    {
        $at = $record['at'] ?? null;

        try {
            $given = is_string($at) ? Carbon::parse($at) : null;
        } catch (Throwable) {
            $given = null;
        }

        return $given === null || $given->greaterThan(now()) ? now() : $given;
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
