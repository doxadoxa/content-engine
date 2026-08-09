<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long the pages already answering this query run to.
 *
 * Measured once and kept, because reading it costs eight fetches from other
 * people's servers and an article is regenerated on every refresh and every
 * retry. Null means nobody has measured it yet, which is different from a
 * measurement of zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->unsignedInteger('serp_target_words')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('serp_target_words');
        });
    }
};
