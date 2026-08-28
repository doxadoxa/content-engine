<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * `php artisan mail:probe you@example.com` — the one thing a deploy cannot
 * assume.
 *
 * Mail is the only outbound dependency here whose failure is silent from the
 * inside. A wrong key, an unverified sending domain, a DKIM record that was
 * pasted with a line break — none of them make the application unhealthy, and
 * the first report is a person who never got a verification link and had no
 * way to tell anyone, because telling anyone required the account they could
 * not finish creating.
 *
 * Sent inline rather than through the queue that carries the real thing.
 * Queued, a rejection lands in Horizon and this command reports success for
 * having enqueued it, which is the opposite of what somebody running a probe
 * wants to know. The point is to make the provider's answer arrive here, on
 * this terminal, attached to the exit code.
 */
class MailProbeCommand extends Command
{
    protected $signature = 'mail:probe
        {recipient : Where to send it}
        {--mailer= : Send through this mailer instead of the configured default}';

    protected $description = 'Send one message through the configured mailer and report what happened';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');
        $mailer = $this->option('mailer') !== null ? (string) $this->option('mailer') : (string) config('mail.default');

        $from = (string) config('mail.from.address');

        // Named before anything is attempted, because the exception this would
        // otherwise raise is a TypeError from inside the SDK about an argument
        // that must be a string — true, and no help at all to somebody who has
        // just deployed and wants to know which environment variable is
        // missing.
        $transports = $this->transportsBehind($mailer);

        if ($transports === null) {
            // Laravel resolves a failover chain eagerly and with no cycle
            // check of its own, so building this mailer does not fail — it
            // recurses until the process dies of memory exhaustion, and what
            // that prints is a fatal error naming a line in the container and
            // nothing whatever about mail. Finding this is what a probe is for.
            $this->components->error("Mailer [{$mailer}] is defined in terms of itself and cannot be built.");

            return self::FAILURE;
        }

        if (in_array('resend', $transports, true) && blank(config('services.resend.key'))) {
            $this->components->error('RESEND_API_KEY is not set, so the resend mailer has nothing to authenticate with.');

            return self::FAILURE;
        }

        $this->components->info("Sending through [{$mailer}] as [{$from}].");

        try {
            Mail::mailer($mailer)->raw(
                "This is a probe from the Avyo engine.\n\n".
                "If you are reading it, the mail path works: mailer [{$mailer}], from [{$from}].\n".
                'Nothing about your account has changed.',
                fn (Message $message) => $message
                    ->to($recipient)
                    ->subject('Mail probe')
            );
        } catch (Throwable $e) {
            // The provider's own words, not a summary of them. "Domain is not
            // verified" and "invalid API key" are different jobs to fix and
            // there is no value in flattening them into "sending failed".
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Accepted, which is not the same as delivered — a message the
        // provider takes and then bounces has left here successfully. Said
        // plainly, because the next question is always whether it arrived.
        $this->components->info("Accepted by [{$mailer}] for {$recipient}. Delivery is the provider's to report.");

        return self::SUCCESS;
    }

    /**
     * Every transport a mailer name can end up using, which is not one
     * transport and not the name.
     *
     * `failover` and `roundrobin` are lists of other mailers, and Laravel
     * builds all of them when the parent is resolved — so a `failover` with
     * `resend` in it and no key raises the SDK's TypeError before the first
     * send is attempted, from a mailer whose own transport is `failover`.
     * Checking only the named mailer would skip the guard in exactly the
     * configuration production is most likely to be in.
     *
     * Null rather than a list when the chain refers back to itself. That is a
     * typo and not an impossibility, and it is reported rather than walked
     * because walking it is the same runaway recursion the framework has.
     *
     * @param  list<string>  $seen
     * @return list<string>|null
     */
    private function transportsBehind(string $mailer, array $seen = []): ?array
    {
        if (in_array($mailer, $seen, true)) {
            return null;
        }

        $config = config("mail.mailers.{$mailer}");

        if (! is_array($config)) {
            return [];
        }

        $transport = $config['transport'] ?? null;

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $behind = [];

            foreach ((array) ($config['mailers'] ?? []) as $child) {
                $childTransports = $this->transportsBehind((string) $child, [...$seen, $mailer]);

                if ($childTransports === null) {
                    return null;
                }

                $behind = [...$behind, ...$childTransports];
            }

            return $behind;
        }

        return is_string($transport) ? [$transport] : [];
    }
}
