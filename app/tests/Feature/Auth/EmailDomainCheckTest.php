<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A mistyped domain is an error on the form, not a mail that never arrives.
 *
 * The live cases use `.invalid`, which DNS is reserved never to answer for, so
 * the check refuses it without going to the network and these stay offline.
 */
final class EmailDomainCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function signing_up_with_a_domain_that_takes_no_mail_is_refused(): void
    {
        config(['auth.check_email_domains' => true]);

        $this->post('/register', $this->signup('alex@courlty.invalid'))
            ->assertSessionHasErrors([
                'email' => 'That address can’t receive email. Check the spelling, especially after the @.',
            ]);

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function with_the_check_off_the_address_still_has_to_be_one(): void
    {
        config(['auth.check_email_domains' => false]);

        $this->post('/register', $this->signup('alex@'))->assertSessionHasErrors('email');
        $this->post('/register', $this->signup('alex@example.test'))->assertSessionHasNoErrors();
    }

    #[Test]
    public function changing_to_a_domain_that_takes_no_mail_is_refused_too(): void
    {
        config(['auth.check_email_domains' => true]);

        $user = User::factory()->create(['email' => 'alex@example.test']);

        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => $user->name, 'email' => 'alex@courlty.invalid'])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $this->assertSame('alex@example.test', $user->fresh()?->email);
    }

    #[Test]
    public function renaming_does_not_re_check_an_address_that_is_not_changing(): void
    {
        // The profile form sends the current address with every name edit.
        // No mail goes to it, so a domain that fails the lookup today — a DNS
        // outage at the provider, or records since withdrawn — must not block
        // somebody from correcting their name. `.invalid` stands in for that.
        config(['auth.check_email_domains' => true]);

        $user = User::factory()->create(['name' => 'Alex', 'email' => 'alex@legacy.invalid']);

        $this->actingAs($user)
            ->put('/user/profile-information', ['name' => 'Alex Moreira', 'email' => 'alex@legacy.invalid'])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Alex Moreira', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function the_check_is_on_unless_somebody_turns_it_off(): void
    {
        // Production sets its variables in a dashboard, not from
        // `.env.example`, so an unset variable has to mean "on". The suite
        // sets it off in all three places tests/bootstrap.php writes to, so
        // take it out of all three and put it back after.
        $key = 'AUTH_CHECK_EMAIL_DOMAINS';
        $was = getenv($key);

        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            // `require`, not the loaded config: the file evaluated again
            // against the environment it just lost.
            $config = require config_path('auth.php');
        } finally {
            if ($was !== false) {
                $_ENV[$key] = $was;
                $_SERVER[$key] = $was;
                putenv("{$key}={$was}");
            }
        }

        $this->assertTrue($config['check_email_domains']);
    }

    /**
     * @return array<string, string>
     */
    private function signup(string $email): array
    {
        return [
            'name' => 'Alex Moreira',
            'email' => $email,
            'password' => 'a-strong-enough-password',
            'password_confirmation' => 'a-strong-enough-password',
        ];
    }
}
