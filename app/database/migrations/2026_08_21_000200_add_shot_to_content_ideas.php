<?php

declare(strict_types=1);

use App\ContentStudio\ContentStudioAssistant;
use App\Support\Social\ContentMix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photograph this idea gets, decided while the month is still one thing.
 *
 * Variety across a set is a property of the set, and this engine already knows
 * that in one place: {@see ContentMix} exists because no amount of checking one
 * idea at a time catches a month where every idea is fine and all twenty are
 * how-tos. The pictures had the same problem and a worse remedy — the drafting
 * step read the subjects already stored on the plan and was told to differ from
 * them, which works only if the drafts are written one after another.
 *
 * They are not. A month is drafted by fanning out one run per idea, so eighteen
 * runs start within a second of each other, each reads a plan with almost
 * nothing stored on it, and each brief is written in ignorance of its nineteen
 * siblings. Measured on a real month: 33 of 40 briefs described a hand, 21 a
 * gloved hand, 14 a brush. The rule had passed its earlier test only because
 * that test ran against a plan that already had eighteen drafted items in it.
 *
 * So the decision moves to the one place that sees the whole month at once. The
 * planner writes twenty ideas in a single answer; it can give each a distinct
 * shot in that answer, and {@see ContentStudioAssistant} then hands the writer
 * a subject rather than asking it to invent one blind. The race is not raced
 * better, it stops existing.
 *
 * Nullable, because every idea planned before this column existed has no shot
 * and must still draft: a null falls back to the old behaviour, which is the
 * writer inventing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->string('shot', 500)->nullable()->after('angle');
        });
    }

    public function down(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->dropColumn('shot');
        });
    }
};
