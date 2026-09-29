<?php

declare(strict_types=1);

namespace App\Blog;

use App\Models\BlogPost;

/**
 * What a publish or update came to: the post, and whether the delivery was
 * too old to be written over it (see {@see BlogInbox::store()}).
 */
final readonly class StoredPost
{
    public function __construct(
        public BlogPost $post,
        public bool $stale,
    ) {}
}
