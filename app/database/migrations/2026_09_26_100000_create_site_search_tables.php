<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-property Search Console history, kept apart from `page_metrics`.
 *
 * Page metrics hang off a tracked SitePage and exist to measure one page before
 * and after a change. These rows hang off nothing but the project: they are the
 * site as Google sees it, readable the moment Search Console is connected and
 * before anybody has chosen a page. Folding them into `page_metrics` would need
 * a nullable `site_page_id`, and every page query would have to remember to
 * exclude the site's own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_search_days', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('measurement_read_id')->constrained()->cascadeOnDelete();
            $table->date('measured_on');
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('impressions');
            $table->unsignedInteger('position_tenths')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'measured_on']);
        });

        Schema::create('site_search_top_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('measurement_read_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('period', 16);
            $table->date('window_from');
            $table->date('window_to');
            $table->text('value');
            $table->char('value_hash', 64);
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('impressions');
            $table->unsignedInteger('position_tenths')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'kind', 'period', 'value_hash'], 'site_search_top_rows_unique');
            $table->index(['project_id', 'kind', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_search_top_rows');
        Schema::dropIfExists('site_search_days');
    }
};
