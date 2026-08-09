<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's connection to an outside account.
 *
 * Per project and not per installation: two projects are two different people's
 * websites, and a single set of credentials in the environment would let either
 * one read the other's search data. The app's own OAuth client id stays in the
 * environment — that identifies *us* to Google — but the grant belongs to the
 * project whose owner clicked consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_integrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->string('provider');

            // Ciphertext at rest, via the model's `encrypted` casts. A database
            // dump is the likeliest way these leave the building, and a refresh
            // token is a standing grant to read somebody's analytics until they
            // notice and revoke it.
            $table->text('refresh_token')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();

            // What the operator actually granted. Stored because a user can
            // untick a scope on the consent screen, and a feature that then
            // fails with a 403 an hour later should instead never have offered
            // itself.
            $table->json('scopes')->default('[]');

            // Which Search Console site and which GA4 property, once chosen.
            $table->json('config')->default('{}');

            // Who connected it, for the settings screen to say so. Nulled
            // rather than cascaded: losing the operator must not silently drop
            // a working connection.
            // foreignId, not foreignUlid: projects are ULID-keyed but users
            // are not, and the two are easy to mix up in a file full of ULIDs.
            $table->foreignId('connected_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Set when Google stops honouring the grant — revoked, password
            // changed, consent withdrawn. A connection that is broken has to
            // say so on the screen; retrying it on a schedule forever is how a
            // project quietly stops collecting data for a month.
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // One connection per provider per project. A second grant replaces
            // the first rather than sitting beside it, because "which of these
            // two tokens is the live one" has no good answer.
            $table->unique(['project_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_integrations');
    }
};
