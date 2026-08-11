<?php

declare(strict_types=1);

use App\Enums\PostKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each planned idea is, beside what it is about.
 *
 * Nullable would have been the smaller migration and the wrong one: every read
 * path would then carry a "kind or the default" branch, and the default would
 * end up written three times and disagree once. A stored default plus a
 * backfill puts the decision in one place.
 *
 * **Existing rows become `how_to`, and that is a statement of fact rather than
 * a convenient default.** Every idea in every plan this migration will run
 * against is a tip — "Four signs you may be planning maintenance", "Upholstery
 * care starts with knowing when" — which is precisely the imbalance the column
 * exists to make visible. Backfilling them as anything else would hide the
 * thing on the first day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->string('kind', 32)->default(PostKind::HowTo->value)->after('pillar');
        });

        DB::table('content_ideas')->update(['kind' => PostKind::HowTo->value]);
    }

    public function down(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
