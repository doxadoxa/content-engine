<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Contracts\EmbeddingGateway;
use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeEmbeddingGateway;
use App\Ai\FakeModelGateway;
use App\Ai\LaragentModelGateway;
use App\Ai\OpenAiEmbeddingGateway;
use App\Audit\PageSpeed\Contracts\PageSpeedGateway;
use App\Audit\PageSpeed\FakePageSpeed;
use App\Audit\PageSpeed\GooglePageSpeedInsights;
use App\Enums\ChannelType;
use App\Feedback\Contracts\AnalyticsGateway;
use App\Feedback\Contracts\CitationChecker;
use App\Feedback\Contracts\SearchConsoleGateway;
use App\Feedback\FakeAnalytics;
use App\Feedback\FakeCitationChecker;
use App\Feedback\FakeSearchConsole;
use App\Feedback\GoogleAnalytics;
use App\Feedback\GoogleSearchConsole;
use App\Feedback\ModelCitationChecker;
use App\Media\AtlasSeedreamImageGeneration;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Onboarding\AdvanceProjectLaunch;
use App\Onboarding\Contracts\SiteReader;
use App\Onboarding\FakeSiteReader;
use App\Onboarding\HttpSiteReader;
use App\Pipelines\Events\PipelineRunFinished;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\ThreadsPublisher;
use App\Publishing\WebhookPublisher;
use App\Research\AhrefsKeywordSource;
use App\Research\Contracts\KeywordSource;
use App\Research\DataForSeoKeywordSource;
use App\Research\FakeKeywordSource;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Contracts\LlmVisibilityGateway;
use App\Visibility\DataForSeoLlmVisibility;
use App\Visibility\FakeLlmVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Events\ContextHydrated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A singleton, so the resolved-model cache inside it is shared for the
        // life of the request or job. The tenant id itself lives in the
        // context, not in this instance.
        $this->app->singleton(CurrentProject::class);

        // The one door to a language model (§3.3). Bound here rather than
        // resolved at each call site, so the test environment can put the fake
        // behind it and there is no second path to the network.
        //
        // A singleton in tests too, and that matters more there: a test asserts
        // against the calls the fake recorded, which only works when the fake
        // it configured is the fake the step was handed.
        $this->app->singleton(ModelGateway::class, fn (): ModelGateway => $this->app->environment('testing')
            ? new FakeModelGateway
            : $this->app->make(LaragentModelGateway::class));

        // Same arrangement for embeddings (§8.4) and images (§8.3).
        $this->app->singleton(EmbeddingGateway::class, fn (): EmbeddingGateway => $this->app->environment('testing')
            ? new FakeEmbeddingGateway
            : $this->app->make(OpenAiEmbeddingGateway::class));

        $this->app->singleton(ImageGenerationProvider::class, fn (): ImageGenerationProvider => $this->app->environment('testing')
            ? new FakeImageGeneration
            : $this->app->make(AtlasSeedreamImageGeneration::class));

        // The feedback loop's two outside sources (§9.1, §9.3).
        $this->app->singleton(SearchConsoleGateway::class, fn (): SearchConsoleGateway => $this->app->environment('testing')
            ? new FakeSearchConsole
            : $this->app->make(GoogleSearchConsole::class));

        $this->app->singleton(AnalyticsGateway::class, fn (): AnalyticsGateway => $this->app->environment('testing')
            ? new FakeAnalytics
            : $this->app->make(GoogleAnalytics::class));

        $this->app->singleton(CitationChecker::class, fn (): CitationChecker => $this->app->environment('testing')
            ? new FakeCitationChecker
            : $this->app->make(ModelCitationChecker::class));

        // Asking the assistants themselves (§9.3, extended). A separate door
        // from CitationChecker on purpose: that one asks our model what it
        // remembers, this one asks ChatGPT and reads the reply.
        $this->app->singleton(LlmVisibilityGateway::class, fn (): LlmVisibilityGateway => $this->app->environment('testing')
            ? new FakeLlmVisibility
            : $this->app->make(DataForSeoLlmVisibility::class));

        // Reading somebody else's website, for onboarding.
        $this->app->singleton(SiteReader::class, fn (): SiteReader => $this->app->environment('testing')
            ? new FakeSiteReader
            : $this->app->make(HttpSiteReader::class));

        // What a browser experiences on the project's own pages, for the site
        // audit. Unlike its neighbours here, the real implementation is
        // unconfigured on most installations and says so — the audit treats an
        // absent key as "no speed score" rather than as an outage.
        $this->app->singleton(PageSpeedGateway::class, fn (): PageSpeedGateway => $this->app->environment('testing')
            ? new FakePageSpeed
            : $this->app->make(GooglePageSpeedInsights::class));

        // Which transport reaches which channel (§9). Two members, and the
        // second one arriving as a line here rather than as a search for every
        // place that assumed webhook is the whole point of the registry.
        //
        // The Threads line is conditional on `social.enabled`, and leaving it
        // out is the correct shape rather than a convenient one. A registry
        // entry is a claim that this installation can reach that type; with no
        // Meta app it cannot, and registering the publisher anyway would make
        // the claim by construction and disprove it one delivery at a time.
        //
        // An existing Threads channel survives the switch being turned off, and
        // {@see PublishToChannels} already says what happens to it: `enabled()`
        // selects channels by `publishableTypes()`, so the channel is "a
        // destination that was never selected" rather than a delivery that
        // fails. That is a skip, but not a silent one — `manualTargets()` reads
        // through the same filter, so the unit card counts zero channels and
        // the publish button offers what it will actually do. The dishonest
        // version of this is a registry that accepts the channel and a
        // transport that throws on the way out, which is the same outcome
        // discovered later and attributed to Meta.
        $this->app->singleton(ChannelPublisherRegistry::class, function (): ChannelPublisherRegistry {
            $registry = (new ChannelPublisherRegistry($this->app))
                ->register(ChannelType::Webhook, WebhookPublisher::class);

            if (config('social.enabled')) {
                $registry->register(ChannelType::Threads, ThreadsPublisher::class);
            }

            return $registry;
        });

        // Same arrangement for keyword data (§4.1): one door, a fake behind it
        // in tests, and no second path out to a vendor.
        //
        // Two live adapters while the switch to DataForSEO is being proved. The
        // thresholds downstream — `minimum_volume`, `maximum_difficulty` — were
        // set against Ahrefs numbers, and Google Ads volumes are a different
        // distribution, so `KEYWORD_SOURCE=ahrefs` has to stay a working way
        // back until they have been measured again rather than carried over.
        $this->app->singleton(KeywordSource::class, function (): KeywordSource {
            if ($this->app->environment('testing')) {
                return new FakeKeywordSource;
            }

            return config('research.source') === 'ahrefs'
                ? $this->app->make(AhrefsKeywordSource::class)
                : $this->app->make(DataForSeoKeywordSource::class);
        });
    }

    public function boot(): void
    {
        $this->forceHttpsAwayFromLocalhost();

        // Accessing a relation that was not loaded is a bug that only shows up
        // as a slow page; failing on it in development is how it gets found.
        // Left off in production, where a missed eager-load should degrade
        // rather than 500.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an attribute the model does not declare is silently
        // ignored by default, which turns a typo in a field name into data
        // that was never saved.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Context is hydrated once per queued job, which is the boundary where
        // a worker stops being about the previous project. Anything the tenant
        // service memoised belongs to that job, not this one.
        Event::listen(ContextHydrated::class, fn () => $this->app->make(CurrentProject::class)->flushResolved());

        // The engine's one outward signal, and what chains a new project's
        // first research → planning → generation without the runner knowing
        // anything about onboarding.
        //
        // The listener lives in App\Onboarding rather than App\Listeners on
        // purpose: Laravel auto-discovers the latter, and a listener that is
        // both discovered and registered here runs twice — which meant one
        // finished research run started two planning runs and planned the
        // month twice.
        Event::listen(PipelineRunFinished::class, AdvanceProjectLaunch::class);
    }

    /**
     * Build https links for every host but the one on this machine.
     *
     * Reached through a tunnel, the app is behind TLS it never terminates: the
     * edge speaks https to the browser and plain http to the origin, so the
     * request Laravel sees says http and every redirect it builds says http
     * too. Assets survived that — they come from APP_URL — and redirects did
     * not, which is the worst shape for a bug to have: the page loads, the form
     * submits, and the browser silently refuses to follow a redirect from https
     * to http as mixed content. The login button spun forever and nothing was
     * logged anywhere.
     *
     * A rule about the host rather than trust in a header. `X-Forwarded-Proto`
     * is the tidier answer and it is one hop away from being right, but it puts
     * the scheme in the hands of whatever last touched the request; this cannot
     * be got wrong by a proxy that forgets to set it.
     *
     * Localhost is the exception because that is the one place the app is
     * genuinely served over http — `docker compose up` and every test that
     * follows. Forcing https there would break the local stack to fix the
     * remote one.
     */
    private function forceHttpsAwayFromLocalhost(): void
    {
        // Console has no request to read a host from, and nothing to force: a
        // command builds links from APP_URL, which already carries its scheme.
        if ($this->app->runningInConsole()) {
            return;
        }

        $host = $this->app->make('request')->getHost();

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return;
        }

        URL::forceScheme('https');
    }
}
