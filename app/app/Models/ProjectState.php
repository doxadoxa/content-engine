<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToProject;
use Database\Factories\ProjectStateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One day in the life of the whole project (§6).
 *
 * Presence is not a property of a URL, and §9 reads both data sources per unit.
 * "More people are searching for us by name" and "people arrive without asking
 * Google" are facts about the project, and this is the row they are written to.
 *
 * Every vendor figure is nullable, and that is load-bearing rather than
 * defensive: a gateway that was not connected on a given day has to read as
 * "not measured", because a zero in the same column would put a hole in the
 * trend and the trend is the only thing here that carries meaning.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $captured_on
 * @property int|null $brand_impressions
 * @property int|null $brand_clicks
 * @property array<string, int>|null $brand_queries
 * @property int|null $total_sessions
 * @property int|null $direct_sessions
 * @property int|null $referral_sessions
 * @property int|null $social_sessions
 * @property int|null $social_engaged_sessions
 * @property int|null $social_engagement_seconds
 * @property int|null $conversions
 * @property int|null $followers
 * @property int|null $post_impressions
 * @property int|null $post_replies
 * @property int|null $post_likes
 * @property int|null $post_reposts
 * @property int|null $post_quotes
 * @property int $posts_published
 * @property int $replies_sent
 * @property array<string, mixed>|null $entity_coverage
 * @property array<string, mixed> $raw
 */
class ProjectState extends Model
{
    use BelongsToProject;

    /** @use HasFactory<ProjectStateFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'captured_on',
        'brand_impressions',
        'brand_clicks',
        'brand_queries',
        'total_sessions',
        'direct_sessions',
        'referral_sessions',
        'social_sessions',
        'social_engaged_sessions',
        'social_engagement_seconds',
        'conversions',
        'followers',
        'post_impressions',
        'post_replies',
        'post_likes',
        'post_reposts',
        'post_quotes',
        'posts_published',
        'replies_sent',
        'entity_coverage',
        'raw',
    ];

    /**
     * Only `raw` is defaulted. `brand_queries` and `entity_coverage` are
     * deliberately absent: null there means the sweep never ran, which the
     * dashboard reads differently from "it ran and found nothing".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'raw' => '{}',
    ];

    /**
     * Replies per impression — the governor's input from §4.3.
     *
     * Null, not zero, when there is nothing to divide by. A day the channel was
     * not measured and a day nobody replied are different facts, and the
     * governor cuts frequency when the trailing rate falls below a threshold:
     * reporting 0.0 for an unmeasured day would throttle a project for a
     * missing API call.
     *
     * Null again for anything a reply rate cannot be, and the two cases are
     * worth arguing separately.
     *
     * A negative denominator is refused because Postgres accepts one.
     * `unsignedInteger` is a no-op in Laravel's Postgres grammar — it emits a
     * plain `integer` with no CHECK — so before the constraint that now backs
     * it up, a written `-5` produced a rate of `-0.6`, which is below every
     * threshold there is and would have throttled the project's frequency
     * permanently through §4.3's governor. The check stays here as well as in
     * the schema: this method is what the governor reads, and a column
     * constraint added later than the data does not clean the rows that got in
     * before it.
     *
     * A rate above 1 — more replies than impressions — is refused rather than
     * reported. It cannot be true of one day's figures, so what it actually
     * means is that the two numbers came from different windows or a different
     * account, and a figure that cannot be true is not a measurement. Null
     * already carries "not measured" everywhere else on this row and the
     * governor's only response to it is to skip the day; a 3.2 would instead be
     * averaged into the trailing rate, hold it above the threshold, and quietly
     * keep a project's frequency uncut on the strength of a broken number. The
     * failure directions are not symmetric, so the choice is not either.
     *
     * **A missing numerator is refused too, and it used to be a zero.**
     * `post_replies ?? 0` read "the replies were never measured" as "nobody
     * replied", which is the same mistake the paragraph above rejects for the
     * denominator, taken in the direction that costs the most: a day with real
     * impressions and an absent reply count reported a rate of exactly 0.0, the
     * governor counted it as a measured day, and the project throttled to the
     * bottom of §1's 2–5 range because one field of one API response was
     * missing. The two columns are filled by the same read and can genuinely
     * arrive apart — `threads_manage_insights` is granted per metric — so the
     * only honest rate is the one where both halves are numbers.
     */
    public function replyRate(): ?float
    {
        if ($this->post_impressions === null || $this->post_impressions <= 0) {
            return null;
        }

        if ($this->post_replies === null) {
            return null;
        }

        $rate = $this->post_replies / $this->post_impressions;

        return $rate < 0 || $rate > 1 ? null : $rate;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_on' => 'date',
            'brand_impressions' => 'integer',
            'brand_clicks' => 'integer',
            'brand_queries' => 'array',
            'total_sessions' => 'integer',
            'direct_sessions' => 'integer',
            'referral_sessions' => 'integer',
            'social_sessions' => 'integer',
            'social_engaged_sessions' => 'integer',
            'social_engagement_seconds' => 'integer',
            'conversions' => 'integer',
            'followers' => 'integer',
            'post_impressions' => 'integer',
            'post_replies' => 'integer',
            'post_likes' => 'integer',
            'post_reposts' => 'integer',
            'post_quotes' => 'integer',
            'posts_published' => 'integer',
            'replies_sent' => 'integer',
            'entity_coverage' => 'array',
            'raw' => 'array',
        ];
    }
}
