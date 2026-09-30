<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Http\Requests\ChannelRequest;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\PagePublicationOperation;
use App\Models\Project;
use App\Models\SitePage;
use App\Publishing\Articles\ArticleSchedules;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\ConnectionHealth;
use App\Publishing\ConnectionSecret;
use App\Publishing\Pages\PageReceiverClient;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The website page: connect a website, see whether it works, fix it if not.
 *
 * Still "channels" in the code and the URL, and "your website" everywhere a
 * person reads. Connecting is URL → a real `ping`, in one press; the secret
 * is Avyo's to make and the owner's to read back for their developer.
 * A channel that has never been pinged is configuration somebody typed; a
 * channel with a `verified_at` is a connection that answered. Saying
 * "connected" on the strength of a saved form is how an operator discovers on
 * publication day that the endpoint was wrong.
 */
class ChannelController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly Entitlements $entitlements,
        private readonly ChannelPublisherRegistry $publishers,
    ) {}

    public function index(): Response
    {
        $channels = Channel::query()->orderBy('created_at')->orderBy('name')->get();
        $project = $this->current->get();
        $limit = $project instanceof Project ? $this->entitlements->for($project)->limit('channels') : null;

        return Inertia::render('channels/index', [
            // Whether "Connect another website" leads anywhere on this plan.
            'can_connect_another' => $limit === null || $channels->count() < $limit,
            'channels' => $channels->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'type_label' => $channel->type->label(),
                'is_enabled' => $channel->is_enabled,
                // Whether a secret exists, never the secret. The model hides
                // the attribute too; this is the deliberate, redacted answer.
                // An owner reads it through `revealSecret`, one press at a
                // time, rather than in every page load.
                'has_secret' => $channel->hasSecret(),
                'native_config' => ['page_receiver_base' => $channel->config['page_receiver_base'] ?? '', 'username' => $channel->config['username'] ?? '', 'endpoint' => $channel->config['endpoint'] ?? ''],
                'can_schedule_articles' => app(ArticleSchedules::class)->compatible($channel),
                'can_test' => $this->testable($channel),
                'verified_at' => $channel->verified_at?->toIso8601String(),
                'health' => $health = ConnectionHealth::for($channel),
                'test_pending' => $health['state'] === 'testing',
                'target' => $this->target($channel),
                'created_at' => $channel->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Connect a website, and test it in the same press.
     *
     * The test used to be a separate button in a table cell, and a website
     * that had never been tested looked the same as one that had been and
     * failed. Now saving is testing: the page comes back saying "Testing…",
     * then either "Connected" or what went wrong.
     */
    public function store(ChannelRequest $request): RedirectResponse
    {
        // The plan's channel limit, enforced where a channel is made.
        //
        // It was advertised on the pricing table and read by nothing, so a
        // Small subscriber could connect as many as they liked — including
        // after downgrading from a plan that allowed them. A shape limit needs
        // a guard at the mutation that changes the shape; there is no counter
        // that will notice later.
        $project = $this->current->get();
        $limit = $project instanceof Project
            ? $this->entitlements->for($project)->limit('channels')
            : null;

        if ($limit !== null && Channel::query()
            ->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => $limit === 1
                    ? 'Your plan connects one website. Remove the current one first, or move to a larger plan.'
                    : "Your plan connects {$limit} websites. Remove one first, or move to a larger plan.",
            ]);
        }

        $attributes = $request->safe()->all();
        unset($attributes['config']['article_publishing_verified']);
        $attributes['config'] = $this->normalisedConfig($attributes['config'] ?? []);

        if ($request->enum('type', ChannelType::class) === ChannelType::Webhook && blank($attributes['secret'] ?? null)) {
            $attributes['secret'] = ConnectionSecret::generate();
        }

        // Refreshed so the column defaults — `is_enabled` above all — are
        // what the test below reads, not the attributes that were posted.
        $channel = Channel::query()->create($attributes)->refresh();

        $testing = $this->test($channel);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $testing ? "{$channel->name} added. Testing the connection…" : "{$channel->name} added.",
        ]);

        return to_route('channels.index');
    }

    public function update(ChannelRequest $request, Channel $channel): RedirectResponse
    {
        $wasEnabled = $channel->is_enabled;
        [$channel, $connectionChanged] = $this->applyUpdate($request, $channel);

        $message = match (true) {
            $connectionChanged && $this->test($channel) => 'Saved. Testing the connection…',
            $wasEnabled && ! $channel->is_enabled => "Paused. Avyo won't send articles to {$channel->name} until you resume.",
            ! $wasEnabled && $channel->is_enabled => "Resumed. Avyo sends articles to {$channel->name} again.",
            default => 'Saved.',
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('channels.index');
    }

    /**
     * Remove a website connection, and its delivery log with it.
     *
     * Refused where removing would lose something that is not only history:
     * an article on its way to this website right now; an article whose last
     * attempt may have arrived without Avyo hearing back, whose delivery row
     * is the receipt identity a retry reconciles against — deleted, the same
     * article could be published twice once a website is connected again; and
     * existing-page updates recorded against it.
     *
     * Articles scheduled for this website go back to waiting for one, rather
     * than keeping a schedule that points at a delivery that no longer exists
     * and saying "Sending…" for ever.
     */
    public function destroy(Channel $channel): RedirectResponse
    {
        // Checked and done under the project's row lock — the one
        // ArticleSchedules::dispatch() takes before it queues an article — so
        // an article cannot be put on its way to this website between the
        // checks and the delete.
        DB::transaction(function () use ($channel): void {
            Project::query()->whereKey($channel->project_id)->lockForUpdate()->firstOrFail();

            $articleDeliveries = fn () => $channel->deliveries()->where(fn ($query) => $query
                ->whereNotNull('content_item_id')->orWhereNotNull('article_schedule_id'));

            if ($articleDeliveries()->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value])->exists()) {
                throw ValidationException::withMessages([
                    'channel' => 'An article is on its way to this website. Try again once it has been sent, or pause the website instead.',
                ]);
            }

            // Uncertain: the request went out and no answer said "not
            // published". A 4xx other than 409 did say so; no answer, a 5xx or
            // an unexpected 2xx did not.
            $uncertain = $articleDeliveries()
                ->where('status', DeliveryStatus::DeadLetter->value)
                ->whereNotNull('article_attempt_started_at')
                ->where(fn ($query) => $query->whereNull('response_code')
                    ->orWhere('response_code', '<', 400)->orWhere('response_code', '>=', 500)->orWhere('response_code', 409))
                ->exists();

            if ($uncertain) {
                throw ValidationException::withMessages([
                    'channel' => "An article may have reached this website without Avyo hearing back. Send it again from the article first, so it isn't published twice, or pause the website instead.",
                ]);
            }

            if (PagePublicationOperation::query()->where('channel_id', $channel->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'channel' => 'Page updates have been published through this website, so it cannot be removed. Pause it instead.',
                ]);
            }

            $waiting = [
                'status' => 'blocked',
                'blocked_reason' => ArticleSchedules::NO_WEBSITE,
                'channel_id' => null,
                'delivery_id' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('article_schedules', 'blocked_code')) {
                $waiting['blocked_code'] = 'no_website';
            }

            ArticleSchedule::query()
                ->where('channel_id', $channel->getKey())
                ->whereIn('status', ['active', 'dispatching', 'blocked'])
                ->update($waiting);

            SitePage::query()->where('channel_id', $channel->getKey())->update(['channel_id' => null]);
            $channel->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$channel->name} removed."]);

        return to_route('channels.index');
    }

    /**
     * A real signed `ping` to the real endpoint.
     *
     * The question asked here used to be `type === Webhook`, which was the
     * right answer to the wrong question. Whether a channel can be tested is a
     * property of the transport behind it (§9) — a second one will want the
     * same button — so the registry is asked instead, and it answers no both
     * for a type nothing can deliver to and for a transport with no dry run.
     */
    public function ping(Channel $channel): RedirectResponse
    {
        abort_unless($this->publishers->canPing($channel->type), 409, 'This kind of connection cannot be tested.');
        abort_if($this->current->get() === null, 409, 'Pick a project first.');

        $this->test($channel);

        return back();
    }

    /**
     * The webhook secret, for the owner to hand to their developer.
     *
     * JSON on request rather than a page prop, so it is in no page load, no
     * Inertia history entry and no prefetch; POST, so no cache or link
     * preview ever holds it.
     */
    public function revealSecret(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->type === ChannelType::Webhook && $channel->hasSecret(), 404);

        Log::info('A webhook secret was revealed', [
            'channel' => $channel->getKey(),
            'project' => $channel->project_id,
            'user' => $request->user()?->getKey(),
        ]);

        return response()
            ->json(['secret' => (string) $channel->secret])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Replace the webhook secret, and test with the new one.
     *
     * The website stops accepting articles until its developer has the new
     * secret, so the connection is unverified from this moment and the test
     * says so if it has not been updated yet.
     */
    public function regenerateSecret(Channel $channel): RedirectResponse
    {
        abort_unless($channel->type === ChannelType::Webhook, 404);

        $channel->forceFill([
            'secret' => ConnectionSecret::generate(),
            'verified_at' => null,
            'config' => [...$channel->config, 'article_publishing_verified' => false],
        ])->save();

        $this->test($channel);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'New secret created. Update it on your website; Avyo is testing the connection.',
        ]);

        return to_route('channels.index');
    }

    /**
     * Send a test if this connection can take one. True when one was sent.
     *
     * A paused connection is not tested: its answer would say "Connected"
     * about a website Avyo is not sending to.
     */
    private function test(Channel $channel): bool
    {
        $project = $this->current->get();

        if (! $project instanceof Project || ! $channel->is_enabled || ! $this->testable($channel)) {
            return false;
        }

        $this->publishers->for($channel->type)->ping($channel, $project);

        return true;
    }

    /**
     * Whether a test has somewhere to go: a transport with a dry run, and the
     * address that transport sends articles to — the webhook address, or the
     * WordPress site. An existing-page address alone receives no articles.
     */
    private function testable(Channel $channel): bool
    {
        $address = match ($channel->type) {
            ChannelType::Webhook => $channel->config['endpoint'] ?? null,
            ChannelType::WordPress => $channel->config['page_receiver_base'] ?? null,
            default => null,
        };

        return is_string($address) && trim($address) !== '' && $this->publishers->canPing($channel->type);
    }

    /**
     * @return array{Channel, bool} The channel, and whether the connection itself changed.
     */
    private function applyUpdate(ChannelRequest $request, Channel $channel): array
    {
        // A blank secret means "leave it alone", not "clear it". An operator
        // renaming a channel should not have to re-paste a token they cannot
        // read back out of the form.
        $changes = $request->safe()->all();

        if (($changes['secret'] ?? null) === null) {
            unset($changes['secret']);
        }

        // The forms send only the keys they show, so what was posted is laid
        // over what is stored rather than replacing it: editing the webhook
        // address must not drop the existing-page address saved beside it.
        if (array_key_exists('config', $changes)) {
            unset($changes['config']['article_publishing_verified']);
            $changes['config'] = [...$channel->config, ...$this->normalisedConfig($changes['config'])];
        }

        $connectionChanged = (string) ($changes['type'] ?? $channel->type->value) !== $channel->type->value
            || (array_key_exists('config', $changes)
                && ! PageReceiverClient::same(self::connectionKeys($changes['config']), self::connectionKeys($channel->config)))
            || array_key_exists('secret', $changes);

        if ($connectionChanged) {
            $changes['verified_at'] = null;
            $changes['config'] = array_merge($changes['config'] ?? $channel->config, ['article_publishing_verified' => false]);
        }

        if (array_key_exists('config', $changes) && ! $connectionChanged) {
            $changes['config']['article_publishing_verified'] = ($channel->config['article_publishing_verified'] ?? false) === true;
        }

        $channel->update($changes);

        return [$channel, $connectionChanged];
    }

    /**
     * The parts of a connection's config that decide where it connects.
     *
     * Missing, null and '' are the same answer — "none" — and must compare
     * equal. The settings form always posts `page_receiver_base`, empty for
     * most websites, while a connection made by onboarding has no such key;
     * compared raw, the first save of an unrelated field read as a new
     * address and threw the website's verification away.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string|null>
     */
    private static function connectionKeys(array $config): array
    {
        $keys = [];

        foreach (['endpoint', 'page_receiver_base', 'username'] as $key) {
            $value = $config[$key] ?? null;
            $keys[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $keys;
    }

    /**
     * Blank connection fields stored as absent rather than as ''.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalisedConfig(array $config): array
    {
        foreach (['endpoint', 'page_receiver_base', 'username'] as $key) {
            if (array_key_exists($key, $config)) {
                $value = $config[$key];
                $config[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return $config;
    }

    /**
     * The one piece of config worth showing in a list: where this actually
     * publishes. Which key holds it depends on the adapter, so the ones that
     * exist are tried in turn rather than assumed.
     */
    private function target(Channel $channel): ?string
    {
        foreach (['page_receiver_base', 'endpoint', 'url', 'handle', 'chat_id'] as $key) {
            $value = $channel->config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
