<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Publishing\Articles\ArticleSchedules;
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
                Rule::requiredIf($type === ChannelType::Webhook && ! $this->filled('config.page_receiver_base')),
                'nullable',
                'url',
                'max:2048',
                app(PublicHttpUrl::class),
            ],
            'config.page_receiver_base' => [Rule::requiredIf($type === ChannelType::WordPress), 'nullable', 'url', 'max:2048', app(PublicHttpUrl::class)],
            'config.username' => [Rule::requiredIf($type === ChannelType::WordPress), 'nullable', 'string', 'max:150', 'regex:/^[^:\r\n]+$/'],
            // Nullable on update: blank means "leave the stored one alone",
            // since the form can never show it back.
            'secret' => [
                Rule::requiredIf(
                    in_array($type, [ChannelType::Webhook, ChannelType::PullApi, ChannelType::WordPress], true)
                    && $channel === null,
                ),
                'nullable',
                'string',
                'max:500',
            ],
            'is_enabled' => ['boolean'],
            'autopublish' => ['boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $channel = $this->route('channel');

            if ($this->boolean('autopublish')
                && (! $channel instanceof Channel || ! app(ArticleSchedules::class)->compatible($channel))) {
                $validator->errors()->add(
                    'autopublish',
                    'Send a successful test before enabling automatic publishing.',
                );
            }

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
            'config.endpoint.url' => 'The endpoint must be a full URL, including https://.',
        ];
    }
}
