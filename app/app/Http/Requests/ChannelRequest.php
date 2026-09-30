<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Rules\PublicHttpUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class ChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $channel = $this->route('channel');
        $type = ChannelType::tryFrom((string) $this->input('type'));
        // A new connection needs its address. An edit that sends no config —
        // pausing, renaming — is not changing where the website is.
        $connecting = $channel === null || $this->has('config');

        return [
            'name' => [
                'required', 'string', 'max:255',
                // Unique per project, because an operator picks a channel by
                // name and two called "Blog" makes that a coin toss.
                Rule::unique('channels', 'name')
                    ->where('project_id', app(CurrentProject::class)->id())
                    ->ignore($channel),
            ],
            'type' => ['required', new Enum(ChannelType::class)],
            'config' => ['array'],
            'config.endpoint' => [
                Rule::requiredIf($type === ChannelType::Webhook && $connecting && ! $this->filled('config.page_receiver_base')),
                'nullable',
                'url',
                'max:2048',
                app(PublicHttpUrl::class),
            ],
            'config.page_receiver_base' => [Rule::requiredIf($type === ChannelType::WordPress && $connecting), 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
            'config.username' => [Rule::requiredIf($type === ChannelType::WordPress && $connecting), 'nullable', 'string', 'max:150', 'regex:/^[^:\r\n]+$/'],
            // Nullable on update: blank means "leave the stored one alone".
            // Never required for a webhook: Avyo makes one when none is
            // pasted, and the owner reads it back on the website page.
            'secret' => [
                Rule::requiredIf(
                    in_array($type, [ChannelType::PullApi, ChannelType::WordPress], true)
                    && $channel === null,
                ),
                'nullable',
                'string',
                'max:500',
            ],
            'is_enabled' => ['boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $channel = $this->route('channel');
            $type = ChannelType::tryFrom((string) $this->input('type'));
            $secret = $this->input('secret');

            if ($type === ChannelType::PullApi && is_string($secret) && $secret !== '') {
                $query = Channel::acrossProjects()
                    ->where('token_hash', Channel::fingerprintPullToken($secret));

                if ($channel instanceof Channel) {
                    $query->where('id', '!=', $channel->getKey());
                }

                $duplicate = $query->exists();

                if ($duplicate) {
                    $validator->errors()->add('secret', 'That pull token is already in use.');
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'config.endpoint.required' => 'Enter the webhook address, starting with https://.',
            'config.endpoint.url' => 'Enter the full webhook address, starting with https://.',
            'config.page_receiver_base.required' => 'Enter your WordPress website address.',
            'config.page_receiver_base.url' => 'Enter the full address, starting with https://.',
            'config.username.required' => 'Enter the WordPress username.',
            'secret.required' => $this->input('type') === ChannelType::WordPress->value
                ? 'Enter the WordPress application password.'
                : 'Enter the token.',
        ];
    }

    /**
     * Fill in what the simple forms leave out.
     *
     * The connect form asks for one thing, the address, so a new connection
     * is named after its host. The website page's small forms — pause, edit
     * the address — send only what they change, so an edit keeps the name
     * and type it already has.
     */
    protected function prepareForValidation(): void
    {
        $channel = $this->route('channel');

        if ($channel instanceof Channel) {
            $this->mergeIfMissing(['name' => $channel->name, 'type' => $channel->type->value]);

            return;
        }

        if (trim((string) $this->input('name')) === '') {
            $this->merge(['name' => $this->nameFromAddress()]);
        }
    }

    /**
     * "example.com" for https://www.example.com/api/hook, made unique within
     * the project so a second connection to the same host still saves.
     */
    private function nameFromAddress(): string
    {
        $address = (string) ($this->input('config.endpoint') ?: $this->input('config.page_receiver_base'));
        $host = parse_url(trim($address), PHP_URL_HOST);
        $base = is_string($host) && $host !== ''
            ? (string) preg_replace('/^www\\./i', '', strtolower($host))
            : 'Website';

        $taken = Channel::query()->pluck('name')->map(fn ($name): string => strtolower((string) $name))->all();
        $name = $base;

        for ($n = 2; in_array(strtolower($name), $taken, true); $n++) {
            $name = "{$base} ({$n})";
        }

        return mb_substr($name, 0, 255);
    }
}
