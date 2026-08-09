<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Models\ContentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PullCursor
{
    public function encode(ContentItem $item): string
    {
        return Crypt::encryptString((string) json_encode([
            'updated_at' => $item->updated_at?->format('Y-m-d\TH:i:s.uP'),
            'id' => $item->getKey(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{updated_at: Carbon, id: string} */
    public function decode(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            $updatedAt = is_array($payload) ? ($payload['updated_at'] ?? null) : null;
            $id = is_array($payload) ? ($payload['id'] ?? null) : null;

            if (! is_string($updatedAt) || ! is_string($id) || ! Str::isUlid($id)) {
                throw new \UnexpectedValueException('Cursor fields are invalid.');
            }

            return ['updated_at' => Carbon::parse($updatedAt), 'id' => $id];
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursor' => 'The cursor is invalid or has expired.',
            ]);
        }
    }
}
