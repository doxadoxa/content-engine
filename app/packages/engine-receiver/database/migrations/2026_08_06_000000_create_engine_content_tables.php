<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_contents', function (Blueprint $table): void {
            $table->id();

            // The engine's id for the unit, and the group that ties its locales
            // together. Both are stable across re-publishes, which is what lets
            // an update replace a row rather than add one.
            $table->string('engine_id')->index();
            $table->string('locale_group_id')->index();
            $table->string('locale', 12);

            $table->string('slug');
            $table->string('type')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('markdown')->nullable();
            $table->longText('html')->nullable();

            $table->json('images');
            $table->json('json_ld');
            $table->json('faq_json_ld');
            $table->json('author');
            $table->json('internal_links');

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // One row per unit per language. An update lands on the same row.
            $table->unique(['engine_id', 'locale']);
            $table->unique(['slug', 'locale']);
        });

        // Idempotency (§2 of the contract). A repeat delivery has nowhere to
        // write a second row, which is what makes "at least once" safe.
        Schema::create('engine_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('event');
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_deliveries');
        Schema::dropIfExists('engine_contents');
    }
};
