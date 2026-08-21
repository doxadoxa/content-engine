<?php

declare(strict_types=1);

use App\Support\Corpus\SiteLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the page actually says, and which kind of page it is.
 *
 * {@see SiteLibrary} has always fetched pages and always thrown the body away,
 * keeping the title and the meta description. That is the right amount to
 * answer "have we written about this already" and it is nothing at all to
 * answer "what is true about this business" — which is the question the planner
 * needs answered and has been guessing at. Its evidence for a month of posts
 * read "Cleaning Point has an article titled X": a sitemap presented as fact.
 *
 * `page_kind` replaces a guess with a reading. `is_article` stays, because
 * `TopicLibrary` is right to use it — a topic library wants topics — but it is
 * a URL-path guess with two values, and the distinction that matters for facts
 * is a third: the pages where a business states its own offer. On the sitemap
 * this was written against, 116 pages are articles and 184 are not, and the
 * ones never fetched include `/services` and `/services/add-ons`.
 *
 * `body` is nullable and stays null for everything except the commercial pages.
 * An article's body is deliberately not stored: sourcing facts from editorial
 * content means sourcing an interpretation, and once the journal is written by
 * this engine it would mean sourcing itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->text('body')->nullable()->after('description');
            $table->string('page_kind', 16)->nullable()->after('is_article');
            $table->index(['project_id', 'page_kind']);
        });
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'page_kind']);
            $table->dropColumn(['body', 'page_kind']);
        });
    }
};
