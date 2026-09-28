<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Color;
use Illuminate\Database\Seeder;

class ColorSeeder extends Seeder
{
    public function run(): void
    {
        $colors = [
            ['name' => 'Black', 'hex_code' => '#000000'],
            ['name' => 'White', 'hex_code' => '#FFFFFF'],
            ['name' => 'Silver', 'hex_code' => '#C0C0C0'],
            ['name' => 'Space Gray', 'hex_code' => '#4B4B4D'],
            ['name' => 'Gold', 'hex_code' => '#D4AF37'],
            ['name' => 'Rose Gold', 'hex_code' => '#B76E79'],
            ['name' => 'Blue', 'hex_code' => '#2563EB'],
            ['name' => 'Red', 'hex_code' => '#DC2626'],
            ['name' => 'Green', 'hex_code' => '#16A34A'],
            ['name' => 'Purple', 'hex_code' => '#7C3AED'],
        ];

        foreach ($colors as $color) {
            Color::firstOrCreate(['name' => $color['name']], $color);
        }
    }
}
