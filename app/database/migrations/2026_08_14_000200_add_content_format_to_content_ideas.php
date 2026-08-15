<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an idea should be made as, when a person has said so.
 *
 * **Nullable, and that is the whole design.** Null means "derive it", which is
 * exactly what the engine did before this column existed — a how-to becomes a
 * carousel on Instagram and everything else becomes a single image. So every
 * row already written keeps behaving identically, and no backfill is needed to
 * make that true.
 *
 * A value means a person disagreed with the derivation, and their answer wins
 * for as long as it is set. That asymmetry is deliberate: the planner is
 * allowed to have an opinion about the shape of an idea it invented, and it is
 * not allowed to overrule somebody who looked at the idea and said "this is a
 * carousel".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            // App\Enums\ContentFormat. A string rather than an enum type for
            // the reason the rest of this schema uses strings: a database enum
            // is a migration every time the set changes, and this set is going
            // to change the moment there is a video pipeline.
            $table->string('content_format')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->dropColumn('content_format');
        });
    }
};
