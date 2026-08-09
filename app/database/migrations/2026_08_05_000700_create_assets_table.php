<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Images (§2): a hero, plus inline images that know where in the body they go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('content_item_id')->constrained()->cascadeOnDelete();

            $table->string('role');

            // Where an inline image goes: the heading slug it follows. A
            // position index would be wrong the moment a section is inserted
            // above it, and a refresh (phase 9) rewrites section order.
            $table->string('anchor')->nullable();

            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('alt')->default('');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->timestamps();

            $table->index(['content_item_id', 'role']);
        });

        // One hero per unit. The publish payload has a single hero field, so a
        // second row means the adapter picks one and the operator sees an image
        // they did not choose.
        DB::statement(
            "CREATE UNIQUE INDEX assets_one_hero_per_item
             ON assets (content_item_id) WHERE role = 'hero'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
