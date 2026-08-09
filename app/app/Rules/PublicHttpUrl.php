<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PublicHttpUrl implements ValidationRule
{
    public function __construct(private readonly PublicHttpTarget $targets) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a public HTTP or HTTPS URL.');

            return;
        }

        try {
            $this->targets->validate($value);
        } catch (UnsafePublicUrl $e) {
            $fail($e->getMessage());
        }
    }
}
