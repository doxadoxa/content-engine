<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Http\Requests\ChannelRequest;
use App\Models\Channel;
use App\Models\Project;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Channels: the list, and the connection wizard §7 asks for.
 *
 * The wizard is URL → secret → a real `ping`, and the third step is the point.
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
    ) {}

    public function index(): Response
    {
        $channels = Channel::query()
            ->withExists(['deliveries as test_pending' => fn ($query) => $query
                ->whereNull('content_item_id')
                ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Retrying->value])])
            ->orderBy('name')
            ->get();

        return Inertia::render('channels/index', [
            'channels' => $channels->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'type_label' => $channel->type->label(),
                'is_social' => $channel->type->isSocial(),
                'is_enabled' => $channel->is_enabled,
                // Whether a secret exists, never the secret. The model hides
                // the attribute too; this is the deliberate, redacted answer.
                'has_secret' => $channel->hasSecret(),
                'autopublish' => $channel->autopublish,
                'verified_at' => $channel->verified_at?->toIso8601String(),
                'test_pending' => (bool) $channel->getAttribute('test_pending'),
                'target' => $this->target($channel),
                'created_at' => $channel->created_at?->toIso8601String(),
            ])->all(),
            'types' => array_map(static fn (ChannelType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'is_social' => $type->isSocial(),
            ], ChannelType::offered()),
        ]);
    }

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

        if ($limit !== null && Channel::query()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => $limit === 1
                    ? 'This plan connects one publishing channel. A larger plan connects more.'
                    : "This plan connects {$limit} publishing channels. A larger plan connects more.",
            ]);
        }

        $channel = Channel::query()->create($request->safe()->all());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$channel->name} added. Send a test delivery to confirm it answers.",
        ]);

        return to_route('channels.index');
    }

    public function update(ChannelRequest $request, Channel $channel): RedirectResponse
    {
        // A blank secret means "leave it alone", not "clear it". An operator
        // toggling auto-publish should not have to re-paste a token they
        // cannot read back out of the form.
        $changes = $request->safe()->all();

        if (($changes['secret'] ?? null) === null) {
            unset($changes['secret']);
        }

        $connectionChanged = (string) ($changes['type'] ?? $channel->type->value) !== $channel->type->value
            || (array_key_exists('config', $changes)
                && ($changes['config']['endpoint'] ?? null) !== ($channel->config['endpoint'] ?? null))
            || array_key_exists('secret', $changes);

        if ($connectionChanged) {
            $changes['verified_at'] = null;
            $changes['autopublish'] = false;
        }

        $channel->update($changes);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$channel->name} updated."]);

        return to_route('channels.index');
    }

    /**
     * Step three of the wizard: a real signed `ping` to the real endpoint.
     *
     * The question asked here used to be `type === Webhook`, which was the
     * right answer to the wrong question. Whether a channel can be tested is a
     * property of the transport behind it (§9) — a second one will want the
     * same button — so the registry is asked instead, and it answers no both
     * for a type nothing can deliver to and for a transport with no dry run.
     */
    public function ping(Channel $channel, ChannelPublisherRegistry $publishers, CurrentProject $current): RedirectResponse
    {
        abort_unless($publishers->canPing($channel->type), 409, 'This kind of channel cannot receive a test delivery.');

        $project = $current->get();

        abort_if($project === null, 409, 'Pick a project first.');

        // No content unit needed: a ping carries only the envelope and the
        // signature (§3 of the contract), and a channel is normally connected
        // before the project has written anything at all.
        $publishers->for($channel->type)->ping($channel, $project);

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => "Test queued for {$channel->name}. It will show Connected after the receiver answers.",
        ]);

        return back();
    }

    public function autopublish(Channel $channel, ChannelPublisherRegistry $publishers): RedirectResponse
    {
        abort_unless($publishers->canAutopublish($channel->type), 409, 'This kind of channel cannot publish automatically.');

        $enable = ! $channel->autopublish;

        abort_if($enable && $channel->verified_at === null, 409, 'Test this channel successfully before enabling automatic publishing.');

        $channel->forceFill(['autopublish' => $enable])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $enable
                ? "{$channel->name} will publish approved content automatically."
                : "{$channel->name} now waits for an explicit publish action.",
        ]);

        return back();
    }

    /**
     * The one piece of config worth showing in a list: where this actually
     * publishes. Which key holds it depends on the adapter, so the ones that
     * exist are tried in turn rather than assumed.
     */
    private function target(Channel $channel): ?string
    {
        foreach (['endpoint', 'url', 'handle', 'chat_id'] as $key) {
            $value = $channel->config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
