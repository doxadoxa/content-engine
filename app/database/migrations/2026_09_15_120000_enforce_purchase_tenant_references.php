<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_sources', fn (Blueprint $table) => $table->unique(['project_id', 'id']));
        Schema::table('purchase_records', function (Blueprint $table): void {
            $table->unique(['project_id', 'purchase_source_id', 'id'], 'purchase_records_source_identity_unique');
            $table->foreign(['project_id', 'purchase_source_id'], 'purchase_records_source_tenant_fk')->references(['project_id', 'id'])->on('purchase_sources');
            $table->foreign(['project_id', 'site_page_id'], 'purchase_records_page_tenant_fk')->references(['project_id', 'id'])->on('site_pages');
        });
        Schema::table('purchase_events', function (Blueprint $table): void {
            $table->foreign(['project_id', 'purchase_source_id'], 'purchase_events_source_tenant_fk')->references(['project_id', 'id'])->on('purchase_sources');
            $table->foreign(['project_id', 'purchase_source_id', 'purchase_record_id'], 'purchase_events_record_tenant_fk')->references(['project_id', 'purchase_source_id', 'id'])->on('purchase_records');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_events', function (Blueprint $table): void {
            $table->dropForeign('purchase_events_record_tenant_fk');
            $table->dropForeign('purchase_events_source_tenant_fk');
        });
        Schema::table('purchase_records', function (Blueprint $table): void {
            $table->dropForeign('purchase_records_page_tenant_fk');
            $table->dropForeign('purchase_records_source_tenant_fk');
            $table->dropUnique('purchase_records_source_identity_unique');
        });
        Schema::table('purchase_sources', fn (Blueprint $table) => $table->dropUnique(['project_id', 'id']));
    }
};
