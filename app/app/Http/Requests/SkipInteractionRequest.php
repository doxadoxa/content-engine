<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\InteractionSkipReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Leaving a conversation alone, on the record.
 *
 * §7 makes the reason mandatory, so it is `required` here and not merely
 * encouraged in the interface: an operator clearing a queue on a phone will
 * take whatever the fastest path is, and the fastest path has to be the one
 * that writes down why.
 */
class SkipInteractionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', new Enum(InteractionSkipReason::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why. A conversation that disappears with no reason is indistinguishable from one that was lost.',
        ];
    }
}
