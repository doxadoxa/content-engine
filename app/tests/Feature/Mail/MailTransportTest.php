<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The provider, and the header nobody notices until somebody replies.
 *
 * The suite sends through the `array` transport and always will, so nothing
 * here proves a mail leaves the building. What it does prove is that the two
 * pieces of wiring a deploy depends on are still present: the Resend transport
 * can be built at all, and a reply-to configured in the environment reaches
 * the message rather than being silently dropped.
 */
final class MailTransportTest extends TestCase
{
    #[Test]
    public function the_resend_mailer_can_still_be_built(): void
    {
        // The transport ships with the framework but is inert without the
        // `resend/resend-php` SDK, which arrives as an explicit requirement
        // and could leave again in any dependency cleanup. Nothing else in the
        // suite would notice: every other test sends through `array`, so the
        // first sign would be verification mail failing in production.
        config(['services.resend.key' => 're_key_that_is_never_used']);
        Mail::forgetMailers();

        $transport = Mail::mailer('resend')->getSymfonyTransport();

        $this->assertInstanceOf(ResendTransport::class, $transport);
    }

    #[Test]
    public function a_configured_reply_to_reaches_the_message(): void
    {
        config(['mail.reply_to' => ['address' => 'hello@avyo.test', 'name' => 'Avyo']]);
        Mail::forgetMailers();

        Mail::raw('Body.', fn (Message $message) => $message
            ->to('someone@example.test')
            ->subject('Subject'));

        $replyTo = $this->lastMessage()->getReplyTo();

        $this->assertCount(1, $replyTo);
        $this->assertSame('hello@avyo.test', $replyTo[0]->getAddress());
        $this->assertSame('Avyo', $replyTo[0]->getName());
    }

    #[Test]
    public function an_unset_reply_to_adds_no_header(): void
    {
        // The shipped default. A `reply_to` array whose address is null must
        // produce no header rather than an empty one — an empty Reply-To is a
        // malformed header, and some providers reject the message for it.
        config(['mail.reply_to' => ['address' => null, 'name' => 'Avyo']]);
        Mail::forgetMailers();

        Mail::raw('Body.', fn (Message $message) => $message
            ->to('someone@example.test')
            ->subject('Subject'));

        $this->assertSame([], $this->lastMessage()->getReplyTo());
    }

    private function lastMessage(): Email
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();

        /** @var Email $message */
        $message = $transport->messages()->last()->getOriginalMessage();

        return $message;
    }
}
