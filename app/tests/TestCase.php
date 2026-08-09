<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test that forgets to fake an endpoint should fail, not phone the
        // vendor. Every port is bound to a fake in this environment, but three
        // things reach the network directly and are not behind one — reading a
        // ranking page for its word count, reading a site during onboarding,
        // and posting to a publish webhook — so "the ports are faked" was never
        // the whole guarantee.
        //
        // Found by a test in this suite doing exactly that: an unfaked URL
        // resolved out to the internet and failed on DNS. It failed harmlessly
        // because the host did not exist. A real host would have been a real
        // request, billed to real credentials, from a test run.
        Http::preventStrayRequests();
    }
}
