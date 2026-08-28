<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs this service, and what they did.
 *
 * There has been no such concept until now — only an email allow-list in the
 * environment, read by the Horizon gate. That is a bootstrap mechanism rather
 * than a permission model: it cannot be granted or revoked without a deploy, it
 * has no record of who is on it, and an address changing hands silently
 * transfers access to every tenant's queues.
 *
 * The allow-list is kept, and only for what it is good at: naming the first
 * administrator on a fresh deployment, when there is nobody who could grant the
 * flag. Everything after that is the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        // Whoever the environment already trusts with Horizon. Not a widening:
        // these addresses can already read every tenant's failed job payloads,
        // so the panel shows them less than they have today.
        $allowed = array_filter(array_map(
            static fn (mixed $email): string => strtolower(trim((string) $email)),
            (array) config('horizon.allowed_emails', []),
        ));

        if ($allowed !== []) {
            DB::table('users')->whereIn(DB::raw('lower(email)'), $allowed)->update(['is_admin' => true]);
        }

        Schema::create('admin_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Who. Null on delete rather than cascade: the record that a
            // subscription was comped must outlive the account that comped it,
            // which is most of the point of writing it down.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // A verb, and what it was done to.
            $table->string('action');
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();

            // Before and after, as json, because "what changed" is the question
            // this table exists to answer and a message would have to be parsed
            // to answer it.
            $table->json('before');
            $table->json('after');

            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });
    }
};
