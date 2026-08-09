<?php

declare(strict_types=1);

use App\Models\ContentItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures a rewrite left behind.
 *
 * A rewrite replaces the body, and the new body names none of the old images.
 * The rows stayed anyway — nothing in the engine deletes an asset, and nothing
 * knew they had stopped counting. Three rewrites of one article left twelve
 * inline rows for three pictures, and `ArticleScore` read the table rather than
 * the article: the data panel told an operator the piece had thirteen images
 * when it had four.
 *
 * Marked rather than deleted, which is the whole point of the column. The file
 * is still on disk and still perfectly good, the row still says what it cost
 * and when it was made, and a rewrite that turns out worse than what it
 * replaced can be looked at. What changes is only that a superseded picture
 * stops being part of the article — see {@see ContentItem::assets()},
 * which is where the distinction is enforced for every reader at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('height');

            // Every read is "the current pictures of this unit", so the index
            // that already exists is the one to extend rather than a new one.
            $table->index(['content_item_id', 'superseded_at']);
        });

        // Existing strays, matched on the markdown the body would carry if the
        // picture were still in it. `illustrate_draft` writes `![{alt}]({url})`
        // and sets `alt` to the heading, so the alt text is the one thing that
        // ties a row to its place in a specific draft.
        //
        // Not the path, which is what the first version of this tried and why
        // it found nothing: a locale borrows its siblings' files, so four
        // generations of one article left twelve rows pointing at the same
        // three pictures under twelve different headings. Every path was still
        // in the body; none of the older headings were.
        //
        // Heroes are excluded rather than swept: a hero is the article's
        // picture rather than a picture in it, so it is never named in the body
        // and would look stale to any rule written this way.
        DB::statement(<<<'SQL'
            update assets
               set superseded_at = now()
              from content_items
             where assets.content_item_id = content_items.id
               and assets.role = 'inline'
               and assets.superseded_at is null
               and (
                     content_items.body_markdown is null
                  or position('![' || assets.alt || '](' in content_items.body_markdown) = 0
               )
        SQL);
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex(['content_item_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });
    }
};
