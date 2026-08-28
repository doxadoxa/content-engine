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
        $account = User::firstOrCreate(
            ['email' => (string) config('seeding.admin.email')],
            [
                'name' => (string) config('seeding.admin.name'),
                'password' => Hash::make((string) config('seeding.admin.password')),
                'email_verified_at' => now(),
            ],
        );

        $this->ensureThereIsAWayIn($account);
    }

    /**
     * Somebody has to be able to open `/admin`.
     *
     * `users.is_admin` is granted by an administrator, which is fine once one
     * exists and is a deadlock on the day none does. The migration that added
     * the column grants it to the `HORIZON_ALLOWED_EMAILS` list — but on a
     * fresh container migrations run *before* this seeder, so there is no
     * account for that pass to find, and the account it then creates has the
     * column's default. The panel would answer 404 for every account on the
     * installation, with nothing able to change that.
     *
     * Only when there is no administrator at all, which is exactly the
     * bootstrap case and nothing else. A deployment that has revoked its last
     * administrator on purpose is not a case this can distinguish, and getting
     * that wrong is a locked-out operator rather than a leak.
     */
    private function ensureThereIsAWayIn(User $account): void
    {
        if (User::query()->where('is_admin', true)->exists()) {
            return;
        }

        $account->forceFill(['is_admin' => true])->save();
    }
}
