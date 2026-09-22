<?php

namespace Database\Seeders;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(
            ['name' => 'Main Branch'],
            ['is_main' => true, 'is_active' => true]
        );
    }
}
