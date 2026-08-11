<?php

declare(strict_types=1);

use App\Enums\AssetSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a picture came from.
 *
 * Not decoration on the row: it is the fact every later treatment is built on.
 * A composite has to know which of an item's assets is the real photograph so
 * it can tell the provider not to alter it; a brand overlay only ever applies
 * to one that was not generated; and an operator deciding between four
 * candidates is entitled to know which one somebody actually took.
 *
 * Backfilled as generated, which is true of every row that exists when this
 * runs — until now the engine was the only thing that could make one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->string('source', 32)->default(AssetSource::Generated->value)->after('role');
        });

        DB::table('assets')->update(['source' => AssetSource::Generated->value]);
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
