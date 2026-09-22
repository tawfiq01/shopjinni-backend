<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\ProductType;
use Illuminate\Database\Seeder;

class ProductTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Smartphone', 'imei_tracking_default' => true],
            ['name' => 'Feature Phone', 'imei_tracking_default' => null],
            ['name' => 'Tablet', 'imei_tracking_default' => true],
            ['name' => 'Smartwatch', 'imei_tracking_default' => false],
            ['name' => 'Charger', 'imei_tracking_default' => false],
            ['name' => 'Cable', 'imei_tracking_default' => false],
            ['name' => 'Earphone', 'imei_tracking_default' => false],
            ['name' => 'Headphone', 'imei_tracking_default' => false],
            ['name' => 'Power Bank', 'imei_tracking_default' => false],
            ['name' => 'Cover', 'imei_tracking_default' => false],
            ['name' => 'Screen Protector', 'imei_tracking_default' => false],
            ['name' => 'Other Accessory', 'imei_tracking_default' => false],
        ];

        foreach ($types as $type) {
            ProductType::firstOrCreate(['name' => $type['name']], $type);
        }
    }
}
