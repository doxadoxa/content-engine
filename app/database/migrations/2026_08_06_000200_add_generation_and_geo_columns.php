<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What generation writes and what the GEO layer needs (§5.1–5.4, §1's first
 * differentiator).
 *
 * The GEO fields are columns rather than one `meta` blob on purpose. §1 makes
 * citability the product's main claim, and "did this unit ship with FAQ schema
 * and quotable blocks" has to be answerable by a query — a claim nobody can
 * count is a claim nobody can hold the engine to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // §1: crypto and finance are YMYL. Fact-checking and real author
            // names are a required stage there, not a setting — this flag is
            // what a step reads to know it may not be skipped.
            $table->boolean('is_ymyl')->default(false);

            // §5.4: auto-publish is a privilege a pipeline earns on a specific
            // project, so it is a project flag and never a global default.
            $table->boolean('autopublish')->default(false);

            // §4.3/§1: the original business data generation is allowed to use —
            // prices, cases, local specifics. Passed to the draft step as an
            // explicit input, because the alternative is the model inventing it.
            $table->json('original_data')->default('{}');

            // Real names for author schema (E-E-A-T). Required on a YMYL
            // project; the fact-check step refuses to pass without one.
            $table->json('authors')->default('[]');
        });

        Schema::table('content_items', function (Blueprint $table): void {
            $table->json('outline')->default('[]');
            $table->text('body_markdown')->nullable();
            $table->text('body_html')->nullable();

            // One sentence. Doubles as the meta description and as this unit's
            // line in an llms.txt (§5.3) — which is all "llms.txt compatible"
            // means at this end: having something true and short to put in it.
            $table->text('summary')->nullable();

            $table->json('json_ld')->default('{}');
            $table->json('faq_json_ld')->default('{}');

            // Self-contained paragraphs written to be quoted by an AI answer
            // without the surrounding article (§1, §5.3).
            $table->json('quotable_blocks')->default('[]');

            // Which of the unit's entities the body actually covers. Checkable
            // rather than declarative, which §5.3 asks for by name.
            $table->json('entity_coverage')->default('{}');

            $table->json('factcheck')->default('{}');
            $table->json('author')->default('{}');

            // Heading slugs the writer left an image for. Phase 8 fills them;
            // phase 5's job is only to leave the anchor.
            $table->json('image_anchors')->default('[]');

            // The daily worker's query, and the calendar's.
            $table->index(['project_id', 'state', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'state', 'scheduled_for']);
            $table->dropColumn([
                'outline', 'body_markdown', 'body_html', 'summary',
                'json_ld', 'faq_json_ld', 'quotable_blocks', 'entity_coverage',
                'factcheck', 'author', 'image_anchors',
            ]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['is_ymyl', 'autopublish', 'original_data', 'authors']);
        });
    }
};
