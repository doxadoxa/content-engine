<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pages the site already has.
 *
 * Onboarding has recorded a `sitemap_url` since it existed and nothing ever
 * read it, so internal links could only ever point at articles this engine had
 * written itself — which on a new project is none, and every article shipped
 * with zero internal links.
 *
 * Cached on the project rather than crawled per article: a sitemap is one
 * request that answers the same question for every unit written that week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('site_pages')->default('[]');
            $table->timestamp('site_pages_fetched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['site_pages', 'site_pages_fetched_at']);
        });
    }
};
