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
            'type' => ['required', $this->typeRule($channel instanceof Channel ? $channel : null)],
            'config' => ['array'],
            'config.endpoint' => [
                Rule::requiredIf($type === ChannelType::Webhook),
                'nullable',
                'url',
                'max:2048',
                app(PublicHttpUrl::class),
            ],
            // Nullable on update: blank means "leave the stored one alone",
            // since the form can never show it back.
            'secret' => [
                Rule::requiredIf(
                    in_array($type, [ChannelType::Webhook, ChannelType::PullApi], true)
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
                && (! $channel instanceof Channel || $channel->verified_at === null)) {
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

    /**
     * A valid type is one this deployment offers — plus, on an update, whatever
     * the row already is.
     *
     * The second half is the part worth spelling out. `ChannelType::offered()`
     * drops Threads when the social presence is switched off, and a rule built
     * from it alone would make an existing Threads channel uneditable: the form
     * posts the type back unchanged, and the operator would be told that the
     * thing in front of them is not a valid kind of thing. Turning the feature
     * off is a decision to stop reaching Threads, not a decision to strand the
     * rows that already say so — they stay renameable and disableable, which is
     * most of what anyone would want to do with one at that point.
     */
    private function typeRule(?Channel $channel): Enum
    {
        $rule = new Enum(ChannelType::class);
        $offered = ChannelType::offered();

        $withdrawn = array_values(array_filter(
            ChannelType::cases(),
            static fn (ChannelType $type): bool => ! in_array($type, $offered, true)
                && $type !== $channel?->type,
        ));

        return $withdrawn === [] ? $rule : $rule->except($withdrawn);
    }
}
