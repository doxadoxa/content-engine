<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Analytics adds to a day's reading.
 *
 * Nullable, and that is the whole design: a project with no GA4 connection has
 * no sessions, which is a different fact from a project that had none. Zero
 * would tell the degradation check that every article on every unconnected
 * project had collapsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('sessions')->nullable();

            // GA4's own definition: over ten seconds, or a conversion, or two
            // pages. Used rather than bounce rate because it is what the API
            // reports and what the operator sees in their own dashboard.
            $table->unsignedInteger('engaged_sessions')->nullable();

            // Seconds, summed across sessions. Divided by sessions when shown;
            // stored raw so a week of them adds up without averaging averages.
            $table->unsignedInteger('engagement_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_metrics', function (Blueprint $table): void {
            $table->dropColumn(['sessions', 'engaged_sessions', 'engagement_seconds']);
        });
    }
};
