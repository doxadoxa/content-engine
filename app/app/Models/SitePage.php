<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Database\Factories\SitePageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A page that was on the project's site before this engine existed.
 *
 * Not a {@see ContentItem}: nothing here was planned, written or scheduled by
 * us, and it has no state to move through. What it has is a topic somebody has
 * already covered, which is the one thing the planner has to know about it.
 *
 * @property string $id
 * @property string $project_id
 * @property string $url
 * @property string $title
 * @property string|null $description
 * @property Carbon|null $published_at
 * @property bool $is_article
 * @property Carbon|null $read_at
 */
class SitePage extends Model
{
    use BelongsToProject;

    /** @use HasFactory<SitePageFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'url',
        'title',
        'description',
        'published_at',
        'is_article',
        'read_at',
    ];

    /**
     * Pages worth comparing a planned topic against.
     *
     * A service page or a contact form is not a topic somebody has covered, and
     * excluding an article because the site has a /pricing page would be worse
     * than not checking at all.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeArticles(Builder $query): Builder
    {
        return $query->where('is_article', true);
    }

    /**
     * What is embedded: the title and, where the page has been read, its own
     * description. Together they are what the page is about, in the words the
     * site itself used.
     */
    public function embeddableText(): string
    {
        return trim($this->title."\n".($this->description ?? ''));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'read_at' => 'datetime',
            'is_article' => 'boolean',
        ];
    }
}
