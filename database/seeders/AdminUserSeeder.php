<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /** Promote the configured, already-registered account without storing credentials in code. */
    public function run(): void
    {
        $email = config('pchat.admin_email');

        if (blank($email)) {
            $this->command?->warn('PCHAT_ADMIN_EMAIL is not configured; admin promotion was skipped.');

            return;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->command?->warn("No PCHAT user exists for {$email}; admin promotion was skipped.");

            return;
        }

        $user->forceFill([
            'role' => 'admin',
            'status' => 'active',
            'suspended_until' => null,
            'ban_reason' => null,
        ])->save();

        $this->command?->info("{$user->email} is now an admin.");
    }
}
