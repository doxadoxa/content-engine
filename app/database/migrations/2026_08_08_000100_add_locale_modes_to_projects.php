<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each of a project's locales gets its content.
 *
 * `locales` already says which languages a project publishes in, and that stays
 * what it means — the visibility sweep measures every one of them, because a
 * brand is findable or invisible in a language whether or not we wrote the page.
 *
 * This says something different and previously unsayable: who *makes* the page.
 * Until now every listed locale got its own planned, written article, which is
 * right for one kind of customer and wrong for another. Cleaning Point receives
 * one article and translates it in-house — so the engine writing four was four
 * separate articles where the receiver expected one, each then translated into
 * four languages. Sixteen pages for one topic.
 *
 * Three modes, and the distinction between the last two is the point:
 *
 *   source    — planned from keyword data and written natively. A keyword lives
 *               in a language and the article has to be in it to rank for it.
 *   adapt     — the engine writes this locale too, for its own audience: same
 *               topic, own angle, own title. Not a translation.
 *   translate — the engine writes nothing. The receiver makes this locale, and
 *               the payload's `locale_group_id` tells it what the page belongs
 *               with.
 *
 * Nullable rather than defaulted to a map, because "this project has not been
 * asked yet" and "this project chose these modes" are different, and the first
 * has to keep behaving the way it did before the column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->jsonb('locale_modes')->nullable()->after('locales');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('locale_modes');
        });
    }
};
