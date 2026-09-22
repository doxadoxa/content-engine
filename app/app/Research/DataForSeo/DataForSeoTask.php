<?php

declare(strict_types=1);

namespace App\Research\DataForSeo;

final readonly class DataForSeoTask
{
    /** @param list<array<string, mixed>> $results
     * @param  array<string, mixed>  $request
     */
    public function __construct(public array $results, public ?string $id, public ?float $cost, public array $request = []) {}
}
