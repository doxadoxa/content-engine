<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How small a search is still worth writing for, per project.
 *
 * This was one number in config for every project, and the number was chosen
 * for a national market: "a pool full of 20-a-month long tail is a pool nobody
 * can plan a month from" is true of a SaaS and false of a cleaning business in
 * Lisbon, where the best keyword in the language is 70 a month and the rest are
 * 30. A global floor made every local project unplannable — research would
 * return sixteen real keywords and pass one.
 *
 * Null means "use the installation default", so nothing changes for a project
 * that never sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedInteger('minimum_volume')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('minimum_volume');
        });
    }
};
