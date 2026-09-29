<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address a blog post used to have, kept so the old URL answers 301.
 *
 * @property int $id
 * @property string $locale
 * @property string $slug
 * @property string $blog_post_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BlogSlugRedirect extends Model
{
    protected $fillable = ['locale', 'slug', 'blog_post_id'];

    /** @return BelongsTo<BlogPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }
}
