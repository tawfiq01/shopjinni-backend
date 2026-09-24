<?php

namespace Database\Seeders;

use App\Domain\Branches\Models\Branch;
use App\Domain\Companies\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $mainBranch = Branch::where('is_main', true)->first();

        // User has no BelongsToCompany auto-fill (see the model's
        // docblock), so company_id has to be set explicitly — the caller
        // (DatabaseSeeder) is expected to run this inside
        // CurrentCompany::forceFor() for the company being provisioned.
        $admin = User::firstOrCreate(
            ['email' => 'admin@mobishop.test'],
            [
                'name' => 'MobiShop Admin',
                'password' => Hash::make('Admin@12345'),
                'branch_id' => $mainBranch?->id,
                'company_id' => CurrentCompany::id(),
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['Admin']);
    }
}
