<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Integrations\Google\GoogleProperties;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GooglePropertiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google', [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect' => 'http://localhost/integrations/google/callback',
        ]);
    }

    #[Test]
    public function unverified_search_console_sites_are_not_offered(): void
    {
        $integration = $this->connected();

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'],
                    ['siteUrl' => 'https://blog.example.com/', 'permissionLevel' => 'siteFullUser'],
                    // Offering this would be offering a choice that returns
                    // nothing rather than an error.
                    ['siteUrl' => 'https://someone-elses.com/', 'permissionLevel' => 'siteUnverifiedUser'],
                ],
            ]),
        ]);

        $sites = app(GoogleProperties::class)->searchConsoleSites($integration);

        $this->assertSame(
            ['sc-domain:example.com', 'https://blog.example.com/'],
            array_column($sites, 'value'),
        );

        // Both spellings have to read as a site to somebody choosing between
        // them in a dropdown.
        $this->assertSame(
            ['example.com (domain)', 'https://blog.example.com'],
            array_column($sites, 'label'),
        );
    }

    #[Test]
    public function analytics_properties_come_back_as_resource_names_across_accounts(): void
    {
        $integration = $this->connected();

        Http::fake([
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [
                    [
                        'displayName' => 'Agency',
                        'propertySummaries' => [
                            ['property' => 'properties/111', 'displayName' => 'example.com'],
                            ['property' => 'properties/222', 'displayName' => 'Another site'],
                        ],
                    ],
                    [
                        'displayName' => 'Personal',
                        'propertySummaries' => [
                            ['property' => 'properties/333', 'displayName' => 'Side project'],
                        ],
                    ],
                ],
            ]),
        ]);

        $properties = app(GoogleProperties::class)->analyticsProperties($integration);

        // The resource name, not the bare number: every Data API call wants
        // `properties/123`, and reassembling it at each call site is a way to
        // get it wrong in one of them.
        $this->assertSame(
            ['properties/111', 'properties/222', 'properties/333'],
            array_column($properties, 'value'),
        );

        // Two accounts can both have a property called "example.com".
        $this->assertSame('Agency · example.com', $properties[0]['label']);
        $this->assertSame('Personal · Side project', $properties[2]['label']);
    }

    #[Test]
    public function a_scope_that_was_never_granted_is_not_asked_for(): void
    {
        $integration = $this->connected(searchOnly: true);

        Http::fake();

        $this->assertSame([], app(GoogleProperties::class)->analyticsProperties($integration));

        // Asking anyway would spend a round trip to be told 403, and would
        // then mark a perfectly good connection broken.
        Http::assertNothingSent();
    }

    #[Test]
    public function the_property_matching_the_project_site_is_suggested(): void
    {
        $properties = app(GoogleProperties::class);

        $options = [
            ['value' => 'properties/111', 'label' => 'Agency · Another site'],
            ['value' => 'sc-domain:example.com', 'label' => 'example.com (domain)'],
        ];

        $this->assertSame(
            'sc-domain:example.com',
            $properties->matching($options, 'https://www.example.com/pricing'),
        );

        // No guess is better than a wrong one: being wrong here costs a month
        // of somebody else's data.
        $this->assertNull($properties->matching($options, 'https://unrelated.test'));
        $this->assertNull($properties->matching($options, null));

        // Only a leading `www.` is a subdomain prefix. Stripping it wherever it
        // appears turns notwww.example.com into example.com and suggests a
        // property for a different site.
        $this->assertNull($properties->matching(
            [['value' => 'sc-domain:example.com', 'label' => 'example.com (domain)']],
            'https://notwww.other.test',
        ));
    }

    #[Test]
    public function every_page_of_analytics_properties_is_read(): void
    {
        $integration = $this->connected();

        Http::fakeSequence()
            ->push([
                'accountSummaries' => [[
                    'displayName' => 'Agency',
                    'propertySummaries' => [['property' => 'properties/111', 'displayName' => 'One']],
                ]],
                'nextPageToken' => 'page-two',
            ])
            ->push([
                'accountSummaries' => [[
                    'displayName' => 'Agency',
                    'propertySummaries' => [['property' => 'properties/222', 'displayName' => 'Two']],
                ]],
            ]);

        $properties = app(GoogleProperties::class)->analyticsProperties($integration);

        // A truncated list is worse than a slow one: the operator scrolls, does
        // not find their property, and concludes we do not support it.
        $this->assertSame(['properties/111', 'properties/222'], array_column($properties, 'value'));
    }

    #[Test]
    public function a_page_token_that_never_ends_does_not_spin_forever(): void
    {
        $integration = $this->connected();

        // Always another page, by Google's account. Following that literally
        // is an infinite loop inside a web request.
        Http::fake([
            'analyticsadmin.googleapis.com/*' => Http::response([
                'accountSummaries' => [[
                    'displayName' => 'Agency',
                    'propertySummaries' => [['property' => 'properties/111', 'displayName' => 'One']],
                ]],
                'nextPageToken' => 'and-another',
            ]),
        ]);

        app(GoogleProperties::class)->analyticsProperties($integration);

        Http::assertSentCount(10);
    }

    #[Test]
    public function a_lost_grant_marks_the_connection_broken(): void
    {
        $integration = $this->connected();

        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401),
        ]);

        $this->assertSame([], app(GoogleProperties::class)->searchConsoleSites($integration));

        // 401 is the grant itself being gone, which is the whole connection and
        // needs a human — the screen has to be able to say "reconnect".
        $this->assertNotNull($integration->refresh()->failure_reason);
        $this->assertFalse($integration->isUsable());
    }

    #[Test]
    public function one_api_refusing_does_not_break_the_other(): void
    {
        $integration = $this->connected();

        Http::fake([
            'analyticsadmin.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
        ]);

        $this->assertSame([], app(GoogleProperties::class)->analyticsProperties($integration));

        // 403 is narrower than 401: this account cannot see this API or this
        // property. Marking the connection broken would take Search Console
        // down because Analytics said no.
        $this->assertNull($integration->refresh()->failure_reason);
        $this->assertTrue($integration->isUsable());
    }

    private function connected(bool $searchOnly = false): ProjectIntegration
    {
        $operator = User::factory()->create();
        $project = Project::factory()->create(['website_url' => 'https://example.com']);
        $operator->projects()->attach($project);

        return app(CurrentProject::class)->run($project, function () use ($searchOnly): ProjectIntegration {
            $factory = ProjectIntegration::factory();

            return ($searchOnly ? $factory->searchOnly() : $factory)->create();
        });
    }
}
