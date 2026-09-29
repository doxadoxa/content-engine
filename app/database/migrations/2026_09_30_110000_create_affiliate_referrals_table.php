<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account a partner referred, and the permission that lets us say so.
 *
 * A table of its own rather than columns on `users`, because what it holds is
 * a small record about one relationship with one third party, and every other
 * account has none of it.
 *
 * - `visitor_id` is the browser id the sign-up was reported under. Kept so a
 *   withdrawal made from that browser can find the account without anybody
 *   having to sign in first.
 * - `email` is the address the sign-up was reported under. Anderro attributes
 *   payments by address, so a customer who changes theirs here must go on
 *   being reported under the one Anderro knows.
 * - `consent_version` and `consented_at` are the marketing consent the report
 *   rests on. Null once it is withdrawn; stale once the cookie inventory moves
 *   on or the twelve months it was given for run out. Either way nothing more
 *   is reported until it is given again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64)->index();
            $table->string('email');
            $table->string('consent_version')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};
