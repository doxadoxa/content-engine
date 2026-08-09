<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('UTC');
            // Locales are first-class from day one (§2): the default is a
            // column so it can be a foreign-key target for defaults, the rest
            // are a list because they are read as a set and never joined on.
            $table->string('default_locale', 12);
            $table->json('locales');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('project_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('operator');
            $table->timestamps();

            // One membership per pair. Without this a double-submitted invite
            // gives a user two rows and every membership count is wrong.
            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
