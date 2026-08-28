<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The probe exists to answer one question — did the provider take it — and it
 * is only worth having if its exit code means that. A probe that reports
 * success because the message was enqueued, or that fails with a stack trace
 * from inside an SDK, sends whoever ran it to the wrong place.
 */
final class MailProbeCommandTest extends TestCase
{
    #[Test]
    public function it_sends_and_reports_the_mailer_it_used(): void
    {
        $this->probe(['recipient' => 'someone@example.test'])
            ->expectsOutputToContain('Sending through [array]')
            ->expectsOutputToContain('Accepted by [array] for someone@example.test')
            ->assertSuccessful();

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        $messages = $transport->messages();

        $this->assertCount(1, $messages);
        $this->assertSame('Mail probe', $messages->first()->getOriginalMessage()->getSubject());
    }

    #[Test]
    public function a_missing_resend_key_is_named_rather_than_thrown(): void
    {
        // The likeliest way this is wrong on a fresh deploy, and the one whose
        // native error — a TypeError about an argument that must be a string —
        // says nothing about which variable to set.
        config(['services.resend.key' => null]);

        $this->probe(['recipient' => 'someone@example.test', '--mailer' => 'resend'])
            ->expectsOutputToContain('RESEND_API_KEY is not set')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_key_is_named_even_behind_a_failover_mailer(): void
    {
        // The configuration production is most likely to be in, and the one
        // that skips a guard which only inspects the named mailer: the
        // transport here is `failover`, never `resend`, but Laravel builds
        // every mailer in the list and the SDK throws before a send is tried.
        config([
            'services.resend.key' => null,
            'mail.mailers.production' => ['transport' => 'failover', 'mailers' => ['resend', 'log']],
        ]);

        $this->probe(['recipient' => 'someone@example.test', '--mailer' => 'production'])
            ->expectsOutputToContain('RESEND_API_KEY is not set')
            ->assertFailed();
    }

    #[Test]
    public function a_failover_mailer_that_names_itself_is_reported_not_walked(): void
    {
        // A typo, not an impossibility, and one the framework has no guard
        // against: `Mail::mailer('loop')` recurses until PHP runs out of
        // memory, and what that prints is a fatal error naming a line in the
        // container and nothing about mail at all.
        config([
            'services.resend.key' => null,
            'mail.mailers.loop' => ['transport' => 'failover', 'mailers' => ['loop']],
        ]);

        $this->probe(['recipient' => 'someone@example.test', '--mailer' => 'loop'])
            ->expectsOutputToContain('defined in terms of itself')
            ->assertFailed();
    }

    #[Test]
    public function an_indirect_cycle_is_reported_too(): void
    {
        config([
            'mail.mailers.first' => ['transport' => 'failover', 'mailers' => ['second']],
            'mail.mailers.second' => ['transport' => 'failover', 'mailers' => ['first']],
        ]);

        $this->probe(['recipient' => 'someone@example.test', '--mailer' => 'first'])
            ->expectsOutputToContain('defined in terms of itself')
            ->assertFailed();
    }

    #[Test]
    public function a_provider_that_refuses_the_message_fails_the_command(): void
    {
        // A transport that cannot connect at all, which is the shape every
        // real failure arrives in: the send throws, and the command has to
        // turn that into an exit code rather than a stack trace.
        config(['mail.mailers.unreachable' => [
            'transport' => 'smtp',
            'scheme' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 1,
        ]]);
        Mail::forgetMailers();

        $this->probe(['recipient' => 'someone@example.test', '--mailer' => 'unreachable'])
            ->assertFailed();
    }

    /**
     * `artisan()` is declared as `PendingCommand|int` — it returns the int
     * directly once the command has been run — so every expectation chained
     * onto it is a call on a possible int as far as static analysis is
     * concerned. Narrowed once here rather than ignored six times.
     *
     * @param  array<string, string>  $arguments
     */
    private function probe(array $arguments): PendingCommand
    {
        $command = $this->artisan('mail:probe', $arguments);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
