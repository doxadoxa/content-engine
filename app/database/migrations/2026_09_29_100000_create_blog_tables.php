<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avyo's own blog: what the engine published to it, the addresses its posts
 * used to have, and every delivery it has already acted on.
 *
 * Deliberately not tenant-scoped. These rows are the public site's content,
 * not a project's data — the project that writes them is only a sender, and
 * the blog knows it by its secret rather than by a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // The engine's id for the unit and the group tying its languages
            // together. Stable across re-publishes, which is what lets an
            // update replace a row rather than add one.
            $table->string('engine_id');
            $table->string('locale_group_id')->index();
            $table->string('locale', 12);

            $table->string('slug');
            $table->string('type', 32)->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('markdown')->nullable();
            $table->longText('html');

            $table->jsonb('images');
            $table->jsonb('json_ld')->nullable();
            $table->jsonb('faq_json_ld')->nullable();
            $table->jsonb('author')->nullable();
            $table->jsonb('internal_links')->nullable();

            $table->timestamp('published_at')->index();
            $table->uuid('last_delivery_id')->nullable();

            // The envelope's `sent_at` of the last delivery acted on. Retries
            // resend the same one and replays a fresh one, so it is what tells
            // a late retry of an older version from the newer one it would
            // otherwise overwrite.
            $table->timestamp('source_sent_at')->nullable();
            $table->timestamps();

            // A deletion is a tombstone, not a missing row: a publish retried
            // after it must find something saying it came too late.
            $table->softDeletes();

            // One row per unit per language, and one post per URL. Tombstones
            // included — see BlogInbox for who gives way when they collide.
            $table->unique(['engine_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });

        // Every address a post has had. A slug the engine renames is a URL
        // somebody linked to and a crawler indexed; it answers 301 rather
        // than 404 for as long as the post it points at is live.
        Schema::create('blog_slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 12);
            $table->string('slug');
            $table->foreignUlid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'slug']);
        });

        // A deletion for a unit the blog has never stored. There is no post
        // to leave a tombstone on, yet the publish it overtook may still be
        // retrying — and without the deletion's `sent_at` to compare against,
        // that retry would create the post the engine had already withdrawn.
        Schema::create('blog_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_id');
            $table->string('locale', 12);
            $table->timestamp('source_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['engine_id', 'locale']);
        });

        // Idempotency (§2 of the contract). A repeat delivery has nowhere to
        // write a second row, which is what makes "at least once" safe.
        Schema::create('blog_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('event', 32);

            // Which post the delivery was about, so a repeat can answer with
            // the post's URL: when the first answer was lost in transit the
            // repeat is the engine's only chance to learn it.
            $table->string('engine_id')->nullable();
            $table->string('locale', 12)->nullable();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_deliveries');
        Schema::dropIfExists('blog_deletions');
        Schema::dropIfExists('blog_slug_redirects');
        Schema::dropIfExists('blog_posts');
    }
};
