<?php

declare(strict_types=1);

namespace Tests\Unit\Corpus;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Ai\UnmeteredSession;
use App\Enums\SitePageKind;
use App\Support\Corpus\PageClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sorting a site's pages into the ones that can carry a fact.
 *
 * The engine read 116 article pages of one site and none of its 184 others,
 * because "is this an article" was decided from URL path markers and nothing
 * asked the other question — where does this business state what it sells. The
 * pages it never opened were `/services` and `/services/add-ons`.
 */
final class PageClassifierTest extends TestCase
{
    #[Test]
    public function it_maps_every_answer_back_onto_the_page_that_asked(): void
    {
        $this->fake('{"pages":[{"page":1,"kind":"commercial"},{"page":2,"kind":"editorial"},{"page":3,"kind":"other"}]}');

        $kinds = app(PageClassifier::class)->classify([
            'a' => ['url' => 'https://x.test/services', 'title' => 'Services', 'text' => 'From 50 EUR.'],
            'b' => ['url' => 'https://x.test/journal/tips', 'title' => 'Tips', 'text' => 'How to clean.'],
            'c' => ['url' => 'https://x.test/contact', 'title' => 'Contact', 'text' => 'Write to us.'],
        ], $this->models());

        $this->assertSame(SitePageKind::Commercial, $kinds['a']);
        $this->assertSame(SitePageKind::Editorial, $kinds['b']);
        $this->assertSame(SitePageKind::Other, $kinds['c']);
    }

    /**
     * A gap in the answer may not shift every page after it.
     *
     * The failure mode of consuming a numbered list positionally, and the
     * reason the contract asks for the number back on each entry.
     */
    #[Test]
    public function a_missing_answer_costs_one_page_and_not_the_rest(): void
    {
        $this->fake('{"pages":[{"page":1,"kind":"commercial"},{"page":3,"kind":"commercial"}]}');

        $kinds = app(PageClassifier::class)->classify([
            'a' => ['url' => 'https://x.test/services', 'title' => 'Services', 'text' => '...'],
            'b' => ['url' => 'https://x.test/about', 'title' => 'About', 'text' => '...'],
            'c' => ['url' => 'https://x.test/prices', 'title' => 'Prices', 'text' => '...'],
        ], $this->models());

        $this->assertSame(SitePageKind::Commercial, $kinds['a']);
        $this->assertSame(SitePageKind::Other, $kinds['b'], 'The unanswered page falls back.');
        $this->assertSame(SitePageKind::Commercial, $kinds['c'], 'And the one after it is still itself.');
    }

    /**
     * Unreadable is `other`, not `commercial`.
     *
     * The costs are not symmetric. A commercial page missed is a fact the
     * planner does without; a contact form admitted is a phone number it may
     * present as evidence.
     */
    #[Test]
    public function an_unreadable_answer_falls_toward_the_harmless_value(): void
    {
        $this->fake('I could not do that.');

        $kinds = app(PageClassifier::class)->classify([
            'a' => ['url' => 'https://x.test/services', 'title' => 'Services', 'text' => '...'],
        ], $this->models());

        $this->assertSame(SitePageKind::Other, $kinds['a']);
    }

    /** Sixty pages is three calls, not sixty. */
    #[Test]
    public function it_asks_once_per_batch_rather_than_once_per_page(): void
    {
        $fake = $this->fake('{"pages":[]}');

        $pages = [];

        for ($i = 0; $i < 60; $i++) {
            $pages["p{$i}"] = ['url' => "https://x.test/{$i}", 'title' => "Page {$i}", 'text' => '...'];
        }

        app(PageClassifier::class)->classify($pages, $this->models());

        $this->assertSame(3, $fake->callCount());
    }

    /** The alternation it asks for is the enum's, so a case cannot go missing. */
    #[Test]
    public function the_contract_offers_every_kind_there_is(): void
    {
        $fake = $this->fake('{"pages":[{"page":1,"kind":"commercial"}]}');

        app(PageClassifier::class)->classify(
            ['a' => ['url' => 'https://x.test/', 'title' => 'Home', 'text' => '...']],
            $this->models(),
        );

        /** @var ModelRequest $request */
        $request = $fake->lastRequest();

        foreach (SitePageKind::cases() as $kind) {
            $this->assertStringContainsString($kind->value, $request->prompt);
        }
    }

    private function fake(string $answer): FakeModelGateway
    {
        $fake = (new FakeModelGateway)->willAnswerUsing(static fn (): string => $answer);
        $this->app->instance(ModelGateway::class, $fake);

        return $fake;
    }

    private function models(): UnmeteredSession
    {
        return app(UnmeteredSession::class);
    }
}
