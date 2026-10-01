<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether articles say they were written with AI.
 *
 * Off by default: a project with a named author, or published under the brand
 * outside the EU, may have no reason to. On, every article ends with a line
 * naming the brand as the publisher responsible for it — which is also what
 * lets a money-or-health project generate without a named person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('ai_disclosure')->default(false);
        });

        // Projects already stuck: money-or-health, launched without a named
        // author, so every article has been stopping at its first step. They
        // get the default a new launch gets — the brand publishes and says AI
        // helped — and the owner can name a person in settings instead.
        DB::table('projects')
            ->where('is_ymyl', true)
            ->whereRaw("NOT EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(authors::jsonb) = 'array' THEN authors::jsonb ELSE '[]'::jsonb END) AS a WHERE btrim(COALESCE(a->>'name', '')) <> '')")
            ->update(['ai_disclosure' => true]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('ai_disclosure');
        });
    }
};
