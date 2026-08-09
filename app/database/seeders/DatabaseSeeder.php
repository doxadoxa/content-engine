<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Enough to sign in. Nothing more.
 *
 * There are deliberately no seeded projects. A project is what the onboarding
 * wizard produces — a site that was read, a brief that was confirmed, and a
 * research run that started from both — and a row inserted here would have
 * none of that while looking exactly like one that did. The first thing an
 * operator sees is the wizard, which is also the only thing that makes a
 * project a real one.
 *
 * Runs on every boot of the app container, so it is written as an upsert: a
 * second `docker compose up` must not fail on a duplicate email or reset a
 * password that was changed.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => (string) config('seeding.admin.email')],
            [
                'name' => (string) config('seeding.admin.name'),
                'password' => Hash::make((string) config('seeding.admin.password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
