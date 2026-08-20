<?php

declare(strict_types=1);

use App\Support\Brand\VisualStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which face a panel is set in.
 *
 * Every carousel this engine has ever drawn is set in Instrument Sans, because
 * the renderer hard-codes it — so a brand whose whole identity is a typeface got
 * its words in somebody else's. The colours have been the brand's since the
 * Brand Brief existed; the type never was.
 *
 * **A slug, not a family name.** The value is a key of
 * {@see VisualStyle::TYPEFACES} and therefore a directory under
 * `resources/fonts` the renderer's image already carries. A free-text family
 * would be a font the container does not have, which renders as whatever
 * Chromium falls back to — silently, and it would be the operator's own font on
 * their own screen that made it look fine in review.
 *
 * **Defaulted to the house face, so nothing already drawn moves.** Every
 * existing brief lands on `instrument-sans`, which is what every panel is
 * already set in, and a schema change may not restyle work that is out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->string('brand_typeface', 40)
                ->default(VisualStyle::DEFAULT_TYPEFACE)
                ->after('brand_palette');
        });
    }

    public function down(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn('brand_typeface');
        });
    }
};
