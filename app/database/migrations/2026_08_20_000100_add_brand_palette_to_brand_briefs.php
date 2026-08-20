<?php

declare(strict_types=1);

use App\Support\Brand\VisualStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the brand's colours, for the times three are not enough.
 *
 * The site read now takes a brand's colours off its stylesheet rather than off a
 * photograph of it, which means exact values and all of them — eight for the
 * first site it was pointed at. The brief kept three, and the other five were
 * shown and thrown away.
 *
 * **What the missing colours cost is legibility, not variety.**
 * {@see VisualStyle::accentType()} already has to decide what to do when a
 * brand's accent cannot carry type on its fill, and with three colours its only
 * move is to give up and use the ink — the emphasis on a `stat` quietly becomes
 * the same colour as everything around it. Its own docblock names the remedy
 * this column supplies: "the honest response is to draw the legible thing and
 * let the operator pick a lighter accent if they want the emphasis back."
 * {@see VisualStyle::readableOn()} records the same failure with a measurement,
 * forest on terracotta at 2.22:1.
 *
 * **A list, not roles.** Ordered by weight on the page, which is the only
 * hierarchy that can be asserted honestly about colours nobody has assigned a
 * job to. Naming slots — `surface_alt`, `accent_alt` — would ask the operator to
 * decide what each colour is *for* and oblige every renderer to honour it.
 *
 * **Empty by default, and empty means "nothing to reach for".** Every existing
 * brief keeps the exact behaviour it has: the fallbacks only consult this list
 * after the current answer has failed the contrast floor, so a brief that
 * renders correctly today renders identically tomorrow. A schema change may not
 * alter the look of work already published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->json('brand_palette')
                ->default('[]')
                ->after('brand_accent');
        });
    }

    public function down(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn('brand_palette');
        });
    }
};
