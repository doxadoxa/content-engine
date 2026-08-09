<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RejectionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class RejectContentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required, and from a closed set: the reason is what phase 9
            // counts, and "other" is a choice rather than a default.
            'reason' => ['required', new Enum(RejectionReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
