<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "I posted this myself" — §11.1's human-assisting half.
 *
 * The same required text as a sent reply, because the row has to say what the
 * brand said whether or not this engine made the HTTP call, plus an optional
 * reference: the link to the reply the operator posted. Optional, because
 * hunting for a permalink on a phone is exactly the friction §7 is trying to
 * remove, and a conversation recorded as answered with no link is still an
 * honest answer.
 */
class RecordHandSentReplyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.(int) config('social.threads.text_limit', 500)],
            'reference' => ['nullable', 'string', 'max:2048'],
            // Recorded rather than enforced on this path: the reply is already
            // in the thread, so a missing tick is a gap in the audit trail and
            // never a reason to refuse. See InteractionReplySender.
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
            'text.required' => 'Record what you actually posted, so the queue and the thread agree.',
        ];
    }
}
