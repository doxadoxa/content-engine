<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_maintenance_checks', function (Blueprint $table): void {
            $table->unique(['project_id', 'site_page_id', 'source_snapshot_id', 'id'], 'maintenance_check_source_identity');
            $table->foreign(['project_id', 'site_page_id', 'source_snapshot_id'], 'maintenance_check_snapshot_fk')->references(['project_id', 'site_page_id', 'id'])->on('page_snapshots');
        });
        Schema::table('fact_maintenance_claims', function (Blueprint $table): void {
            $table->foreign(['project_id', 'site_page_id', 'source_snapshot_id', 'check_id'], 'maintenance_claim_source_fk')->references(['project_id', 'site_page_id', 'source_snapshot_id', 'id'])->on('fact_maintenance_checks');
        });
        Schema::table('fact_usage_impacts', function (Blueprint $table): void {
            $table->foreign(['project_id', 'site_page_id'], 'maintenance_impact_page_fk')->references(['project_id', 'id'])->on('site_pages');
        });
    }

    public function down(): void
    {
        Schema::table('fact_usage_impacts', fn (Blueprint $table) => $table->dropForeign('maintenance_impact_page_fk'));
        Schema::table('fact_maintenance_claims', fn (Blueprint $table) => $table->dropForeign('maintenance_claim_source_fk'));
        Schema::table('fact_maintenance_checks', function (Blueprint $table): void {
            $table->dropForeign('maintenance_check_snapshot_fk');
            $table->dropUnique('maintenance_check_source_identity');
        });
    }
};
