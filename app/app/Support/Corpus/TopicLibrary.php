<?php

declare(strict_types=1);

namespace App\Support\Corpus;

use App\Ai\Contracts\EmbeddingGateway;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SitePage;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Whether a topic has already been covered, by meaning rather than by wording.
 *
 * Words do not work here. This project's site publishes `Limpeza De Carpetes
 * Preco` and the planner was about to schedule `Carpet Cleaning Lisbon`, which
 * share no word at all — they are the same topic in two languages, and a string
 * comparison finds nothing. An embedding puts them next to each other.
 *
 * The same index answers the question for the engine's own published work,
 * which is why both go through here rather than one being a special case.
 */
class TopicLibrary
{
    /** Embedded per run, so one refresh cannot become a large bill. */
    private const int MAX_PER_RUN = 100;

    public function __construct(
        private readonly EmbeddingGateway $embeddings,
        private readonly CurrentProject $current,
    ) {}

    /**
     * Give every unembedded article a vector.
     *
     * Bounded, and only articles: a services page is not a topic anybody
     * covered, and embedding four hundred of them would be paying to compare
     * an article against a contact form.
     */
    public function index(Project $project): int
    {
        // Under the project's tenant, because SitePage is scoped and the scope
        // fails closed: called from a command or a job with no tenant set, the
        // query finds nothing and this silently indexes zero pages while
        // reporting success.
        return $this->current->run($project, fn (): int => $this->indexPending($project));
    }

    /**
     * The site's own article closest to this topic, if any is close enough to
     * be worth considering.
     *
     * "Close enough" is deliberately generous: what comes back may be a
     * duplicate or may be an adjacent subject, and {@see certainDistance()}
     * says which of those the caller can decide on its own.
     *
     * @param  list<float>|null  $vector  a vector already computed for this topic
     * @return array{title: string, url: string, published_at: string|null, distance: float}|null
     */
    public function alreadyCovered(Project $project, string $topic, ?array $vector = null): ?array
    {
        $topic = trim($topic);

        if ($topic === '' && $vector === null) {
            return null;
        }

        // Reused where the caller already has one. Embedding every candidate
        // idea on every planning run is a bill for answering the same question
        // about the same words repeatedly.
        $vector ??= $this->embeddings->embed($topic);

        /** @var list<object{title: string, url: string, published_at: string|null, distance: float}> $rows */
        $rows = DB::select(
            'select title, url, published_at, embedding <=> ?::vector as distance
             from site_pages
             where project_id = ?
               and is_article = true
               and embedding is not null
             order by distance
             limit 1',
            ['['.implode(',', $vector).']', $project->getKey()],
        );

        $nearest = $rows[0] ?? null;

        if ($nearest === null) {
            return null;
        }

        if ($nearest->distance > $this->possibleDistance()) {
            return null;
        }

        return [
            'title' => $nearest->title,
            'url' => $nearest->url,
            'published_at' => $nearest->published_at,
            'distance' => round($nearest->distance, 4),
        ];
    }

    /**
     * The unit's *topic* vector, computed once and kept on the row.
     *
     * Its own column, not the one the corpus index uses. That one holds the
     * article's body so a finished piece can find another to link to; this one
     * holds a handful of words describing the subject. Comparing a query
     * against a body puts them far apart whatever the subject, and writing one
     * into the other's column silently breaks internal linking.
     *
     * @return list<float>
     */
    public function rememberVector(ContentItem $idea, string $text): array
    {
        /** @var list<object{embedding: string|null}> $rows */
        $rows = DB::select(
            'select topic_embedding::text as embedding from content_items where id = ?',
            [$idea->getKey()],
        );

        $stored = $rows[0]->embedding ?? null;

        if (is_string($stored) && $stored !== '') {
            /** @var list<float> $vector */
            $vector = array_map('floatval', explode(',', trim($stored, '[]')));

            if (count($vector) > 1) {
                return $vector;
            }
        }

        $vector = $this->embeddings->embed($text);

        DB::update(
            'update content_items set topic_embedding = ?::vector where id = ?',
            ['['.implode(',', $vector).']', $idea->getKey()],
        );

        return $vector;
    }

    /**
     * Cosine distance below which two topics are the same subject.
     *
     * Judged against real pairs rather than picked round: at 0.25 an article
     * about deep cleaning and one about post-renovation cleaning are separate
     * subjects, and a translation of the same title is well inside it.
     *
     * Config, because it is the dial between "we wrote that twice" and "the
     * month came out empty", and the right setting depends on how broad a
     * project's subject is.
     */
    /** Below this, the same subject and nobody needs asking. */
    public function certainDistance(): float
    {
        return (float) config('research.same_topic_certain', 0.28);
    }

    /** Above this, unrelated subjects and no call is worth making. */
    public function possibleDistance(): float
    {
        return (float) config('research.same_topic_possible', 0.42);
    }

    private function indexPending(Project $project): int
    {
        $pending = SitePage::query()
            ->articles()
            ->whereRaw('embedding is null')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $indexed = 0;

        foreach ($pending as $page) {
            $text = $page->embeddableText();

            if ($text === '') {
                continue;
            }

            $vector = $this->embeddings->embed($text);

            DB::update(
                'update site_pages set embedding = ?::vector where id = ?',
                ['['.implode(',', $vector).']', $page->getKey()],
            );

            $indexed++;
        }

        if ($indexed > 0) {
            Log::info('Indexed existing site articles for the duplicate check', [
                'project' => $project->slug,
                'pages' => $indexed,
            ]);
        }

        return $indexed;
    }
}
