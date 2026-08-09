<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pages a project's site already had before this engine wrote anything.
 *
 * A table rather than the json column it started as, because three different
 * questions are asked of it and only the first works on a list of URLs:
 *
 *   - what can this article link to (a URL and a title)
 *   - has this topic already been covered (a title and a description, compared
 *     by meaning — the site's own posts are often in a different language from
 *     the one we are writing in, so string matching finds nothing)
 *   - how long ago (a date, so "covered recently" and "due a refresh" are
 *     different answers)
 */
return new class extends Migration
{
    /** Same as content_items, because the two are compared against each other. */
    private const int DIMENSIONS = 1536;

    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->text('url');
            $table->string('title');
            $table->text('description')->nullable();

            // `<lastmod>` from the sitemap where the site publishes one. The
            // recency rule needs a date and this is the only one available
            // without fetching every page.
            $table->timestamp('published_at')->nullable();

            // Whether this looks like an article rather than a service page.
            // Only articles are worth comparing a planned topic against.
            $table->boolean('is_article')->default(false);

            // Set once the page itself has been read, as opposed to being
            // known only from the sitemap.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->unique(['project_id', 'url']);
            $table->index(['project_id', 'is_article']);
        });

        // Raw SQL: `vector` is not a type the schema builder knows.
        DB::statement('ALTER TABLE site_pages ADD COLUMN embedding vector('.self::DIMENSIONS.')');

        // The json cache this replaces. It was a week old at most and is
        // rebuilt from the sitemap on the next read, so nothing is lost.
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['site_pages', 'site_pages_fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('site_pages')->default('[]');
            $table->timestamp('site_pages_fetched_at')->nullable();
        });

        Schema::dropIfExists('site_pages');
    }
};
