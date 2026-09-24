<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One price list instead of four.
 *
 * The earlier lists (Small, Medium and Enterprise; the two Local Search and
 * Content Growth offers) were never sold to anybody, so there is no customer
 * to keep on them. Rows still naming one — grandfathered local projects,
 * comps — move to the nearest current plan, and the version columns that
 * pinned them go.
 */
return new class extends Migration
{
    private const MOVED = [
        'small' => 'starter',
        'medium' => 'growth',
        'enterprise' => 'growth',
        'local-search' => 'growth',
    ];

    public function up(): void
    {
        foreach (self::MOVED as $from => $to) {
            DB::table('project_subscriptions')->where('plan', $from)->update(['plan' => $to]);
            DB::table('project_subscriptions')->where('pending_plan', $from)->update(['pending_plan' => $to]);
        }

        Schema::table('project_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['plan_version', 'pending_plan_version']);
        });

        Schema::table('page_improvement_allowances', function (Blueprint $table): void {
            $table->dropColumn('plan_version');
        });
    }

    public function down(): void
    {
        Schema::table('project_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('plan_version')->default(4);
            $table->unsignedInteger('pending_plan_version')->nullable();
        });

        Schema::table('page_improvement_allowances', function (Blueprint $table): void {
            $table->unsignedSmallInteger('plan_version')->nullable();
        });
    }
};
