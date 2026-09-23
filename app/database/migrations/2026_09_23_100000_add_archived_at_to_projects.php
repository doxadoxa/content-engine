<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A way for an owner to be done with a project.
 *
 * A timestamp rather than a delete. Seven foreign keys onto `projects` refuse
 * a delete outright, and the once-per-site free sample is judged by the rows
 * old projects leave behind — so a project somebody no longer wants stays in
 * the table, stopped and out of sight, rather than leaving it.
 *
 * Not Laravel's soft deletes either: that trait scopes every query, including
 * the trial checks and the administrative panel that must go on seeing these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
