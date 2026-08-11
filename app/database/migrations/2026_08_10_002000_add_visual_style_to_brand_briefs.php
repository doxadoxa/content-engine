<?php

declare(strict_types=1);

use App\Models\BrandBrief;
use App\Support\Brand\VisualStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parts of a brand's look that something has to render, not read.
 *
 * `visual_language` already exists and stays: it is prose, it goes into an
 * image prompt, and prose is the right shape for instructing a model. It is the
 * wrong shape for anything that draws. A text overlay needs a colour it can
 * fill with, a typeface it can load and a corner to sit in; "warm, unfussy,
 * always a real workspace" cannot be any of those, and asking a model to turn
 * it into a hex value on every render would make the brand a different colour
 * on Tuesday.
 *
 * Four columns rather than one JSON blob, matching the shape the rest of this
 * table already has: every other field an operator edits is its own column, and
 * a blob would put these outside {@see BrandBrief::CONTENT_FIELDS}
 * and therefore outside the versioning that makes an old post able to say what
 * it was made from.
 *
 * Defaults are deliberately neutral rather than absent. A project that has
 * never opened the brand form still has to be able to render a legible panel,
 * and "no colour" is not a colour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->string('brand_colour', 7)->default(VisualStyle::DEFAULT_COLOUR)->after('visual_language');
            $table->string('brand_ink', 7)->default(VisualStyle::DEFAULT_INK)->after('brand_colour');
            $table->string('overlay_position', 16)->default(VisualStyle::DEFAULT_POSITION)->after('brand_ink');
            $table->string('overlay_case', 16)->default(VisualStyle::DEFAULT_CASE)->after('overlay_position');
        });
    }

    public function down(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn(['brand_colour', 'brand_ink', 'overlay_position', 'overlay_case']);
        });
    }
};
