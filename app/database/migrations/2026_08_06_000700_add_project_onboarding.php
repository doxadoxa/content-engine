<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding: a project is created by a wizard, not by a seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('onboarding_status')->default('active');

            // The site this project is about. Everything the wizard learns
            // starts from it.
            $table->string('website_url')->nullable();
            $table->string('sitemap_url')->nullable();

            // What reading the site produced, kept verbatim. The operator edits
            // the answers, not this — so when a suggestion turns out wrong it
            // is possible to see whether the analysis or the human was.
            $table->json('site_analysis')->default('{}');

            // The wizard's answers, step by step. Survives a closed tab.
            $table->json('onboarding')->default('{}');

            $table->timestamp('onboarded_at')->nullable();

            // §8.1 of the product spec: competitors inform what gets planned.
            $table->json('competitors')->default('[]');

            // Per-project article settings, from the last wizard step.
            $table->json('article_settings')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_status', 'website_url', 'sitemap_url', 'site_analysis',
                'onboarding', 'onboarded_at', 'competitors', 'article_settings',
            ]);
        });
    }
};
