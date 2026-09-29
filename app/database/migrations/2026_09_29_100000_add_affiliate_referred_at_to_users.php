<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a partner's referral of this account was reported to Anderro.
 *
 * A timestamp and nothing else — not the visitor id, not the partner. Anderro
 * holds the attribution; what this application needs to know is only whether
 * the account's payments are any of Anderro's business. For everybody who was
 * not referred, and for anybody who has since withdrawn marketing consent, the
 * answer is no and this is null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('affiliate_referred_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('affiliate_referred_at');
        });
    }
};
