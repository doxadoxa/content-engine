<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What a step asks a model for.
 *
 * Note what is *not* here: a provider, a model name, a temperature. A step asks
 * for a `role` — "draft this", "check these facts" — and config/models.php
 * decides what plays that role. §9 defers the per-step model choice until there
 * is metering data to make it on, and that deferral is only real if changing
 * the answer never touches a step class.
 */
final readonly class ModelRequest
{
    /**
     * @param  string  $role  a key of config('models.roles')
     * @param  array<string, mixed>  $options  provider-specific knobs, passed through
     */
    public function __construct(
        public string $role,
        public string $instructions,
        public string $prompt,
        public array $options = [],
    ) {}

    public static function for(string $role, string $prompt, string $instructions = ''): self
    {
        return new self($role, $instructions, $prompt);
    }
}
