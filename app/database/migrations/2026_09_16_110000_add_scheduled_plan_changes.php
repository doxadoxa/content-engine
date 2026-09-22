<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_subscriptions', function (Blueprint $table): void {
            $table->string('pending_plan')->nullable();
            $table->unsignedInteger('pending_plan_version')->nullable();
            $table->timestamp('pending_plan_at')->nullable();
            $table->string('stripe_schedule_id')->nullable();
            $table->string('stripe_schedule_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_subscriptions', fn (Blueprint $table) => $table->dropColumn(['pending_plan', 'pending_plan_version', 'pending_plan_at', 'stripe_schedule_id', 'stripe_schedule_generation']));
    }
};
