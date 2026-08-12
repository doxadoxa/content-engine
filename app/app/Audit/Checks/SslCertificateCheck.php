<?php

declare(strict_types=1);

namespace App\Audit\Checks;

use App\Audit\CheckFinding;
use App\Audit\Contracts\SiteCheck;
use App\Audit\SiteSignals;
use App\Enums\AuditCheckGroup;

/**
 * HTTPS: that it works, and that it is the only way in.
 *
 * Two separate faults with very different weights. A site that will not serve
 * HTTPS at all is a browser warning in front of every visitor. A site that
 * serves both but does not redirect is merely publishing every page at two
 * addresses, which splits its own ranking signals between them.
 */
class SslCertificateCheck implements SiteCheck
{
    public static function key(): string
    {
        return 'ssl_certificate';
    }

    public function label(): string
    {
        return 'SSL certificate';
    }

    public function description(): string
    {
        return 'Ensures HTTPS is served correctly and that plain HTTP redirects to it.';
    }

    public function group(): AuditCheckGroup
    {
        return AuditCheckGroup::Seo;
    }

    /** @return list<CheckFinding> */
    public function run(SiteSignals $signals): array
    {
        if ($signals->tlsError !== null) {
            // Not "the certificate is invalid", which is only one of the things
            // this can be: the reader reaches here for a refused certificate, a
            // server that is down, a name that does not resolve from the
            // worker, and an address the outbound guard declined. Saying
            // "certificate" would tell a customer whose site was briefly down
            // that their TLS is broken — and charge them a high finding for it.
            return [CheckFinding::high(
                'The site could not be reached over HTTPS.',
                ['error' => $signals->tlsError],
            )];
        }

        if (! $signals->isHttps) {
            return [CheckFinding::high('The site is not served over HTTPS.')];
        }

        // Null is "not checked", which is not the same as "does not redirect".
        // Reporting an unchecked site as misconfigured would be the audit
        // inventing a fault out of its own incompleteness.
        if ($signals->httpRedirectsToHttps === false) {
            return [CheckFinding::medium(
                'Plain HTTP does not redirect to HTTPS, so every page has two addresses.',
            )];
        }

        return [];
    }
}
