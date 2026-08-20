<?php

declare(strict_types=1);

use App\Support\Brand\VisualStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third colour, which the carousel layouts need and the brief never had.
 *
 * A fill and an ink are enough to draw a rectangle of colour with words on it,
 * which is what the one panel template did. The layouts that replaced it have
 * something to emphasise — the figure on a `stat`, the half of a `contrast` that
 * matters, the button on a `cta` — and with two colours the only way to
 * emphasise anything is to use the ink again, so the emphasis disappears.
 *
 * **Empty means "use the ink", not "no colour".** That is the behaviour every
 * existing brief already has, because {@see CarouselPanels} has been passing the
 * ink as the accent since panels existed. Defaulting to a concrete hue would
 * change the look of every carousel on every deployment the moment this
 * migration ran, which is not a thing a schema change may do.
 *
 * Not derived from the brand colour, and that is the point of asking. Rotating a
 * hue to manufacture a complement is exactly the decision that makes an operator
 * wonder where the pink came from — see the comment this replaces in
 * `CarouselPanels::props()`. The site's real accent is knowable; it is simply
 * not knowable from anything this engine currently reads, since site analysis
 * sees extracted text and never a pixel. Until it can look at the site, the
 * person who typed the other two colours types this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->string('brand_accent', 7)
                ->default(VisualStyle::DEFAULT_ACCENT)
                ->after('brand_ink');
        });
    }

    public function down(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn('brand_accent');
        });
    }
};
