<?php

declare(strict_types=1);

use App\ContentStudio\ContentStudioAssistant;
use App\Support\Brand\VisualStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a carousel opens on a photograph or on the brand's own colour.
 *
 * The cover draws its hook over the post's photograph, behind a scrim. That is
 * right for most brands and wrong for some: a teaching format is typographic,
 * and a brand whose covers are flat colour and one enormous line is making a
 * choice rather than missing a picture.
 *
 * **The brief decides, not the model.** The obvious alternative is to let the
 * drafting model pick per post, and it cannot: the copy is written before the
 * photograph exists — {@see ContentStudioAssistant} drafts
 * first and illustrates after — so the model would be choosing a cover for an
 * image it has never seen. It is also a consistency decision rather than an
 * editorial one. A brand whose carousels sometimes open on a photograph and
 * sometimes on navy reads as two accounts, and this file already has a name for
 * that failure: "a brand whose colour is decided by a model is a brand with a
 * different colour every Tuesday."
 *
 * Defaulted to `photo`, which is what every carousel currently draws, so the
 * column changes nothing until somebody sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->string('carousel_cover', 16)
                ->default(VisualStyle::DEFAULT_COVER)
                ->after('brand_typeface');
        });
    }

    public function down(): void
    {
        Schema::table('brand_briefs', function (Blueprint $table): void {
            $table->dropColumn('carousel_cover');
        });
    }
};
