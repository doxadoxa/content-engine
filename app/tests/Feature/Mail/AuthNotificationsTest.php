<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two mails the service cannot work without, and the thread they are not
 * allowed to block.
 *
 * Both are on the critical path of getting an account at all: an unverified
 * address cannot reach the wizard and cannot start a trial, and somebody
 * locked out has only the reset link. Sending either inline puts an HTTP call
 * to a mail provider inside a web request, where a provider having a bad
 * minute becomes a registration that 500s after the user row was already
 * written — an address that is taken by an account nobody can sign into.
 *
 * So what these assert is not that mail is sent. It is that the framework's
 * inline notifications have been replaced by queued ones, which is a thing a
 * routine upgrade could quietly undo.
 */
final class AuthNotificationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_queues_the_verification_mail(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Alex',
            'email' => 'alex@example.test',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ]);

        $user = User::query()->where('email', 'alex@example.test')->sole();

        Notification::assertSentTo($user, VerifyEmail::class, function (BaseNotification $notification): bool {
            // The class alone is not the assertion. Ours extends the
            // framework's, so a revert to the parent would still be *a*
            // VerifyEmail — this is the part that would break.
            return $notification instanceof ShouldQueue;
        });
    }

    #[Test]
    public function asking_for_the_verification_mail_again_queues_it_too(): void
    {
        // The resend button on the "check your inbox" screen. It is the route
        // somebody uses precisely when the first attempt failed, so it is the
        // worst one to have send inline.
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post('/email/verification-notification');

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            fn (BaseNotification $notification): bool => $notification instanceof ShouldQueue
        );
    }

    #[Test]
    public function asking_for_a_password_reset_queues_the_mail(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'alex@example.test']);

        $this->post('/forgot-password', ['email' => 'alex@example.test']);

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (BaseNotification $notification): bool => $notification instanceof ShouldQueue
        );
    }

    #[Test]
    public function the_queued_mail_carries_a_link_that_verifies_the_account(): void
    {
        // The whole point of the mail, and the part that queueing could break
        // without any test above noticing: the notification is built by the
        // worker, not by the request, so anything the URL needs has to survive
        // being serialised and picked up somewhere else.
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $link = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (BaseNotification $notification, array $channels, User $notifiable) use (&$link): bool {
            /** @var VerifyEmail $notification */
            $link = $notification->toMail($notifiable)->actionUrl;

            return true;
        });

        $this->assertIsString($link);

        $this->actingAs($user)->get($link)->assertRedirect();

        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    #[Test]
    public function a_verified_account_is_not_asked_to_verify_again(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/email/verification-notification');

        Notification::assertNothingSent();
    }

    #[Test]
    public function both_notifications_wait_for_the_transaction_and_retry(): void
    {
        // Queued by id, so a worker that ran before the enclosing commit would
        // look for a user that is not there yet. Nothing wraps registration in
        // a transaction today; this is what keeps that from mattering.
        foreach ([new VerifyEmail, new ResetPassword('token')] as $notification) {
            // Asserted through `class_implements` rather than `instanceof`:
            // both classes declare the interface right there in their
            // signature, so static analysis proves an `instanceof` true and
            // rejects it as a tautology — while the thing actually worth
            // protecting is that the declaration is still there at all.
            $implements = class_implements($notification);

            $this->assertContains(ShouldQueue::class, $implements);
            $this->assertContains(
                ShouldQueueAfterCommit::class,
                $implements,
                $notification::class.' must not be dispatched before the transaction commits.'
            );
            // Four attempts, because the last backoff is only waited if an
            // attempt remains after it — at three, the 300 is unreachable and
            // the retry window is seventy seconds rather than the six minutes
            // the notification documents.
            $this->assertSame(4, $notification->tries);
            $this->assertSame([10, 60, 300], $notification->backoff());
            $this->assertCount($notification->tries - 1, $notification->backoff());
        }
    }
}
