<?php

namespace Database\Seeders;

use App\Domain\Branches\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $mainBranch = Branch::where('is_main', true)->first();

        $admin = User::firstOrCreate(
            ['email' => 'admin@mobishop.test'],
            [
                'name' => 'MobiShop Admin',
                'password' => Hash::make('Admin@12345'),
                'branch_id' => $mainBranch?->id,
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['Admin']);
    }
}
