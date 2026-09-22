<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_improvement_allowances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->ulid('proposal_id')->unique();
            $table->unsignedSmallInteger('plan_version')->nullable();
            $table->timestampTz('period_started_at')->nullable();
            $table->unsignedSmallInteger('units');
            $table->timestampTz('accepted_at');
            $table->string('policy');
            $table->timestampsTz();
            $table->foreign(['project_id', 'proposal_id'])->references(['project_id', 'id'])->on('page_proposals');
        });
        // Earlier accepted work and all of its revisions remain included. No
        // historical price/period is invented for approvals before this quota.
        DB::table('page_proposal_reviews')->where('action', 'accept')->orderBy('id')->get()->each(function (object $review): void {
            DB::table('page_improvement_allowances')->insertOrIgnore([
                'id' => (string) Str::ulid(), 'project_id' => $review->project_id, 'proposal_id' => $review->proposal_id,
                'plan_version' => null, 'period_started_at' => null, 'units' => 0, 'accepted_at' => $review->created_at,
                'policy' => 'accepted_before_improvement_allowance', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_improvement_allowances');
    }
};
