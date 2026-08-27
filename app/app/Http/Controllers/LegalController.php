<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/*
 * The three public documents: what you agree to, what we do with your data,
 * and what we store on your device.
 *
 * One controller rather than three, because the three pages differ only in
 * which prose they render and which "last updated" date they carry — every
 * other prop is the same entity block, and three copies of it is three places
 * for the registered address to go stale.
 *
 * Everything here is outside the auth group and deliberately so. A privacy
 * policy you can only read once you have handed over your email is not a
 * privacy policy, and the cookie banner links to these pages before anybody
 * has an account.
 */
final class LegalController extends Controller
{
    public function terms(): Response
    {
        return $this->page('legal/terms', 'terms');
    }

    public function privacy(): Response
    {
        return $this->page('legal/privacy', 'privacy', [
            'subprocessors' => config('legal.subprocessors'),
            'cookies' => config('legal.cookies'),
        ]);
    }

    public function cookies(): Response
    {
        return $this->page('legal/cookies', 'cookies', [
            'cookies' => config('legal.cookies'),
        ]);
    }

    /**
     * The entity block every page carries, plus the date of the one being read.
     *
     * @param  array<string, mixed>  $extra
     */
    private function page(string $component, string $document, array $extra = []): Response
    {
        return Inertia::render($component, array_merge([
            'entity' => [
                'name' => config('legal.entity'),
                'companyNumber' => config('legal.company_number'),
                'address' => config('legal.address'),
                'jurisdiction' => config('legal.jurisdiction'),
                'email' => config('legal.contact_email'),
                'product' => config('legal.product'),
                'site' => config('legal.site'),
                'authority' => config('legal.supervisory_authority'),
            ],
            'updated' => config("legal.updated.{$document}"),
        ], $extra));
    }
}
