<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit as this site received it.
 *
 * @property string $engine_id
 * @property string $locale_group_id
 * @property string $locale
 * @property string $slug
 * @property string $title
 */
class EngineContent extends Model
{
    protected $table = 'engine_contents';

    protected $guarded = [];

    /**
     * The other languages of the same unit — what an `hreflang` block is built
     * from, and the reason the engine sends `locale_group_id` at all.
     *
     * @return Builder<self>
     */
    public function siblings(): Builder
    {
        return static::query()
            ->where('locale_group_id', $this->locale_group_id)
            ->whereKeyNot($this->getKey());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'json_ld' => 'array',
            'faq_json_ld' => 'array',
            'author' => 'array',
            'internal_links' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
