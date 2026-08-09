<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where seasonality lands (§5).
 *
 * `KeywordIdea` is a transient DTO — it is never stored. What *is* stored is
 * the row the research pipeline creates from it, and the keyword's metrics
 * already live on that row as `topic_volume` and `topic_difficulty`. So the
 * monthly curve goes next to them, on `content_items`, and nothing new has to
 * be looked up to answer "when does this subject peak".
 *
 * §5 puts it plainly: both vendors already return the twelve-month curve and
 * the DTO throws it away. Ahrefs sends `volume_by_month`, DataForSEO sends
 * `monthly_searches`, and one column and one method is the entire integration.
 * Planning happens four to six weeks ahead of a peak, which is a question no
 * single averaged volume can answer — a keyword with 1 200 searches a month is
 * either steady work or one December.
 *
 * Shaped as month => searches, `{"2026-01": 880, "2026-02": 720, …}`, because
 * the vendors report calendar months and a bare list of twelve numbers loses
 * which year the curve was measured in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->jsonb('monthly_volumes')->nullable()->after('topic_volume');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('monthly_volumes');
        });
    }
};
