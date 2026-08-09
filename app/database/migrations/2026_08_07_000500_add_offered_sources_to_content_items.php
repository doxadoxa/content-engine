<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reference sources this article was offered, if any.
 *
 * Recorded so the score can tell two different articles apart: one that had
 * public sources available and cited none, and one that had none to cite. A
 * local commercial query returns competitors and nothing else, and refusing to
 * link a rival cleaning company is the right call — the article should not be
 * marked down for making it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->json('offered_sources')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('offered_sources');
        });
    }
};
