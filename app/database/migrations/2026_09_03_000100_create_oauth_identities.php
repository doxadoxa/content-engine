<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing in as somebody Google already knows.
 *
 * **A table rather than a `google_id` column**, for the same reason
 * `project_integrations` is a table: the second provider should be a row, not a
 * migration on `users` and a fourth branch in the callback. What is stored per
 * identity — who the provider says this is, and under what address — is the
 * same shape whoever the provider is.
 *
 * Two unique indexes, and they say different things. `(provider,
 * provider_subject)` is the one that makes this an identity at all: one Google
 * account cannot become two accounts here, which is the whole promise of
 * signing in with it. `(user_id, provider)` says the reverse — one account
 * holds at most one Google identity — so "connect a different Google account"
 * is an update to a row that already exists rather than a second row that the
 * next lookup would have to choose between.
 *
 * **The subject, not the address.** Google's `sub` is the stable identifier and
 * an address is not: people change them, and Google reissues them within a
 * Workspace domain. The address is stored beside it as a record of what was
 * true when the identity was linked — useful when explaining to somebody why
 * they are signed in as who they are — and is never what a lookup keys on.
 *
 * `users.password` becomes nullable in the same migration because it is the
 * same change: an account that has only ever arrived through Google has no
 * password, and a placeholder hash nobody knows would be a credential that
 * exists. The framework already refuses to validate a null one
 * (`EloquentUserProvider::validateCredentials` returns false before it reaches
 * the hasher), so the column being empty is a state the login path understands
 * rather than one it has to be taught.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_subject');
            // What the provider said the address was at the time. A record, not
            // a key: see the class note above.
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');

        // Deliberately one-way for the accounts made through it. Rolling back
        // cannot invent a password for somebody who never had one, so the
        // column is left nullable rather than made `NOT NULL` against rows that
        // would fail it.
    }
};
