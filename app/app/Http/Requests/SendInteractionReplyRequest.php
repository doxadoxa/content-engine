<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Social\ReplyClearance;
use App\Social\ReplyGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The text an operator is about to say in public, as the brand.
 *
 * `text` is required rather than defaulted to the stored draft, and that is the
 * point of the whole screen: §4.2's one touch sends *what the operator is
 * looking at*. A request that could omit the text and have the server fill in
 * "whatever the draft says now" would send something the operator never read
 * the moment a redraft landed between the render and the tap.
 *
 * The length rule is the platform's, read from the same config
 * {@see ReplyGuard} measures against, so a rejection arrives as a form error on
 * the box the operator is typing in rather than as a refusal after the round
 * trip. It is not the enforcement — the guard is, at the send boundary, because
 * an API client is not obliged to use this form.
 *
 * `acknowledged` carries the codes the operator ticked — §10's fact-check duty
 * on a YMYL project, §9's unconfirmed previous send. It is validated as a list
 * of strings and nothing more; which codes exist and which of them clear a
 * finding is {@see ReplyClearance}'s question, and a form request that also
 * knew the answer would be a second place to change it.
 */
class SendInteractionReplyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.(int) config('social.threads.text_limit', 500)],
            'acknowledged' => ['array'],
            'acknowledged.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'There is nothing to send. Write the reply, or skip the conversation.',
            'text.max' => 'Threads refuses anything over :max characters. Shorten the reply.',
        ];
    }
}
