<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mail templates are published into resources/views/vendor/mail, which is
 * a directory the framework will happily do without: delete it, or let a
 * `vendor:publish --force` overwrite it during an upgrade, and every mail
 * silently reverts to Laravel's stock design. Nothing breaks, no test fails,
 * and the first anyone knows is a customer receiving a black button.
 *
 * So these assert on the rendered output rather than on the files existing.
 */
final class MailTemplateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_action_button_is_the_brand_violet(): void
    {
        // The stock theme's is #18181b. If this reads as anything else, the
        // published theme is not the one being used.
        $this->assertStringContainsString('#5c3fe6', $this->renderedVerificationMail());
    }

    #[Test]
    public function the_page_and_card_carry_the_brand_surfaces(): void
    {
        $html = $this->renderedVerificationMail();

        // Canvas behind, and the border of the card on it. Both are copied by
        // hand from resources/css/app.css, which is the whole reason to pin
        // them: nothing else notices when the two drift apart.
        $this->assertStringContainsString('#f7f5f2', $html);
        $this->assertStringContainsString('#ddd9d3', $html);
    }

    #[Test]
    public function no_mail_reaches_out_to_another_companys_cdn(): void
    {
        // The stock header embeds laravel.com's logo whenever the app is named
        // "Laravel". It never fired here, but a remote image in mail sent as
        // us is worth asserting the absence of rather than assuming.
        $this->assertStringNotContainsString('laravel.com', $this->renderedVerificationMail());
    }

    #[Test]
    public function the_footer_says_why_the_mail_arrived(): void
    {
        // Both a courtesy and a small deliverability signal for a domain with
        // no sending history.
        $this->assertStringContainsString(
            'attached to an '.config('app.name').' account',
            $this->renderedVerificationMail()
        );
    }

    private function renderedVerificationMail(): string
    {
        $user = User::factory()->unverified()->create();

        return (string) (new VerifyEmail)->toMail($user)->render();
    }
}
