<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly plan (§3.2). Filled by the planner in phase 4; here it is the
 * thing content items hang off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // Always the first of the month. A date rather than year+month
            // integers so the calendar can range over it directly.
            $table->date('month');
            $table->string('status')->default('draft')->index();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // One plan per project per month. A second one is the planner
            // having run twice, and two calendars for March is worse than an
            // error at the moment the duplicate is written.
            $table->unique(['project_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plans');
    }
};
