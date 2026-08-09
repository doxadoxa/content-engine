<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing targets (§2). Phase 2 records the configuration and the secret;
 * the delivery that uses them is phase 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('name');

            // Per-type settings — endpoint URL, blog id, handle. Shapes differ
            // per type and are validated by the adapter that owns the type, so
            // the column stays open rather than sprouting a nullable per type.
            $table->json('config');

            // Ciphertext, not a token. Longer than the plaintext and not
            // indexable, which is why it is text and has no unique index.
            $table->text('secret')->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // An operator picks a channel by name in the publish UI; two called
            // "Blog" makes that a coin toss.
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
