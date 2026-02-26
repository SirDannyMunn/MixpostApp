<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'password');
        $name = env('ADMIN_NAME', 'Admin User');

        $user = User::query()->where('email', $email)->first();
        if (!$user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
        } else {
            // Ensure name set; do not overwrite password unless empty
            if (!$user->name) {
                $user->name = $name;
                $user->save();
            }
        }

        // Ensure the user has at least one organization as owner
        $org = $user->organizations()->first();
        if (!$org) {
            $orgName = env('ADMIN_ORG_NAME', 'Admin Org');
            $slug = Str::slug($orgName);
            $org = Organization::firstOrCreate(
                ['slug' => $slug],
                ['name' => $orgName, 'subscription_tier' => 'pro', 'subscription_status' => 'active']
            );
            OrganizationMember::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                ['role' => 'owner', 'joined_at' => now()]
            );
        } else {
            // Ensure owner membership on existing org if missing
            OrganizationMember::firstOrCreate(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                ['role' => 'owner', 'joined_at' => now()]
            );
        }
    }
}

