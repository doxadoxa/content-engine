<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * Changing the name and the address on an account.
 *
 * Untested until now for a reason that is itself the bug: nothing was bound to
 * `UpdatesUserProfileInformation`, so this route answered 500 and no test had
 * ever watched it save anything.
 *
 * **Half of these call the action directly, and that is the point.** Fortify's
 * `ProfileInformationController` lower-cases the username before it calls the
 * action, so a test that posts to `/user/profile-information` cannot tell
 * whether the action normalises or whether the controller did it on the way
 * past — it passes either way, which makes it a test of Fortify. The action
 * implements a contract and is meant to hold on its own; the only way to say
 * that in a test is to call it on its own.
 */
final class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_route_saves_a_new_address_and_asks_for_it_to_be_verified(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.com']);

        $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'Alex Moreira',
            'email' => 'alex@newjob.example',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        // The whole route, end to end, which had never once been exercised:
        // before the binding it answered 500 here.
        $this->assertSame('Alex Moreira', $user->name);
        $this->assertSame('alex@newjob.example', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    #[Test]
    public function the_action_lower_cases_the_address_it_stores(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.com']);

        $this->action()->update($user, [
            'name' => 'Alex Moreira',
            'email' => '  Alex@NewJob.Example  ',
        ]);

        $this->assertSame('alex@newjob.example', $user->fresh()->email);
    }

    #[Test]
    public function the_same_address_in_a_different_case_is_not_a_change(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.com']);

        $this->action()->update($user, [
            'name' => 'Alex Moreira',
            'email' => 'ALEX@example.com',
        ]);

        // Re-verification is for a new address, and this is not one. Unfolded,
        // it reads as a change and sends somebody to their inbox over a capital
        // letter.
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[Test]
    public function a_different_case_does_not_get_past_the_unique_rule(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'alex@example.com']);

        // `unique` compares with `=`, which on Postgres is case-sensitive, so
        // unfolded this stores a second spelling of one address.
        $this->expectException(ValidationException::class);

        try {
            $this->action()->update($user, [
                'name' => 'Alex Moreira',
                'email' => 'Taken@Example.com',
            ]);
        } finally {
            $this->assertSame('alex@example.com', $user->fresh()->email);
        }
    }

    private function action(): UpdateUserProfileInformation
    {
        return new UpdateUserProfileInformation;
    }
}
