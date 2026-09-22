<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariantColor>
 */
class ProductVariantColorFactory extends Factory
{
    protected $model = ProductVariantColor::class;

    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'color_id' => Color::factory(),
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'unit' => 'pcs',
            'reorder_level' => 0,
            'is_active' => true,
        ];
    }
}
