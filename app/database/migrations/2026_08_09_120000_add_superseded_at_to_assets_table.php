<?php

declare(strict_types=1);

use App\Models\ContentItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures a rewrite left behind.
 *
 * A rewrite replaces the body, and the new body names none of the old images.
 * The rows stayed anyway — nothing in the engine deletes an asset, and nothing
 * knew they had stopped counting. Four generations of one article left twelve
 * inline rows for three pictures, and `ArticleScore` read the table rather than
 * the article: the data panel told an operator the piece had thirteen images
 * when it had four.
 *
 * Marked rather than deleted, which is the whole point of the column. The file
 * is still on disk and still perfectly good, the row still says what it cost
 * and when it was made, and a rewrite that turns out worse than what it
 * replaced can be looked at. What changes is only that a superseded picture
 * stops being part of the article — see {@see ContentItem::assets()}, which is
 * where the distinction is enforced for every reader at once.
 *
 * The column only, and no backfill. One database ever held rows this was
 * written for and it has been corrected; a fresh installation has no article
 * old enough to have strays, and from here
 * {@see ContentItem::supersedeInlineAssets()} retires them as each rewrite
 * saves. A repair that can never run again would still carry its assumption —
 * that a picture is findable in the body by its alt text — into every future
 * reading of this file, and be wrong there long before anybody noticed.
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
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex(['content_item_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });
    }
};
