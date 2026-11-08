<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\TesterInvitation;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the initial team: one Super Admin, one Developer, and one Tester.
     *
     * Existing accounts are promoted without resetting their password. Password
     * resets only happen when the matching environment variable is filled.
     */
    public function run(): void
    {
        $superAdmin = User::updateOrCreate(
            ['email' => Str::lower((string) env('ADMIN_EMAIL', 'admin@workos.com'))],
            [
                'name' => env('ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'admin123')),
                'role' => UserRole::SuperAdmin->value,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $developerEmail = Str::lower((string) env('DEVELOPER_EMAIL', 'developer@workos.com'));
        $developerPassword = env('DEVELOPER_PASSWORD');

        $developer = User::firstOrCreate(
            ['email' => $developerEmail],
            [
                'name' => env('DEVELOPER_NAME', 'Developer'),
                'password' => Hash::make(filled($developerPassword) ? $developerPassword : 'developer123'),
                'role' => UserRole::Developer->value,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if ($developer->role !== UserRole::Developer->value) {
            $developer->update(['role' => UserRole::Developer->value]);
        }

        if (filled($developerPassword) && ! $developer->wasRecentlyCreated) {
            $developer->update(['password' => Hash::make($developerPassword)]);
        }

        $testerEmail = Str::lower((string) env('TESTER_EMAIL', 'tester@workos.local'));

        $tester = User::firstOrCreate(
            ['email' => $testerEmail],
            [
                'name' => env('TESTER_NAME', 'Tester'),
                // Testers authenticate with their access key, never this password.
                'password' => Hash::make(Str::random(48)),
                'role' => UserRole::Tester->value,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        if (! $tester->isTester()) {
            $tester->update(['role' => UserRole::Tester->value]);
        }

        $accessKey = (string) env('TESTER_ACCESS_KEY', 'tst_demo-access-key-2026');

        $invitation = TesterInvitation::updateOrCreate(
            [
                'owner_id' => $superAdmin->id,
                'email' => $testerEmail,
            ],
            [
                'tester_user_id' => $tester->id,
                'name' => $tester->name,
                'key_hash' => Hash::make($accessKey),
                'key_hint' => substr($accessKey, -4),
                'status' => 'active',
                'expires_at' => null,
                'revoked_at' => null,
            ]
        );

        $invitation->projects()->sync($superAdmin->projects()->pluck('id')->all());
    }
}
