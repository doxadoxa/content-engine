<?php

declare(strict_types=1);

use App\Support\Duty\DutyHours;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project's "окно присутствия" (§4.3, §11.4).
 *
 * A publishing slot is only valid where the operator is available for the next
 * 60–90 minutes. The reason is mechanical rather than polite: the algorithm
 * weighs the speed of replies in the first hour, so a post at 03:00 does worse
 * than no post at all, and a post at 17:55 into a window that closes at 18:00
 * is the same mistake wearing office hours.
 *
 * Shaped as ISO weekday keys with a list of local-time ranges:
 *
 *   {"mon": [["09:00","13:00"], ["14:00","18:00"]], "sat": [["10:00","12:00"]]}
 *
 * Local to the project's own `timezone` column, not UTC. An operator's day is a
 * fact about where they are, and storing it in UTC would move their morning
 * twice a year.
 *
 * Nullable, and null is read as "never on duty" rather than "always" — see
 * {@see DutyHours}. §11.4 makes this a new onboarding question, and until it is
 * answered the planner must not schedule a post into a silence nobody is
 * watching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->jsonb('duty_hours')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('duty_hours');
        });
    }
};
