<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductVariantColorResource;
use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductVariantColorController extends Controller
{
    /**
     * Flat, searchable list of every SKU — used by the catalog product list
     * and (later) the POS search bar.
     */
    public function index(Request $request)
    {
        $query = ProductVariantColor::query()
            ->with(['variant.model.brand', 'variant.model.productType', 'color']);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('sku', 'like', $term)
                    ->orWhere('barcode', 'like', $term)
                    ->orWhereHas('variant.model', fn ($m) => $m->where('name', 'like', $term))
                    ->orWhereHas('variant.model.brand', fn ($b) => $b->where('name', 'like', $term))
                    ->orWhereHas('color', fn ($c) => $c->where('name', 'like', $term));
            });
        }

        return ProductVariantColorResource::collection(
            $query->orderByDesc('id')->paginate($request->integer('per_page', 25))
        );
    }

    public function store(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'color_id' => [
                'required',
                'exists:colors,id',
                Rule::unique('product_variant_colors', 'color_id')->where('product_variant_id', $variant->id),
            ],
            'sku' => ['nullable', 'string', 'max:255', 'unique:product_variant_colors,sku'],
            'barcode' => ['nullable', 'string', 'max:255', 'unique:product_variant_colors,barcode'],
            'imei_tracking_enabled' => ['nullable', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'selling_price_current' => ['nullable', 'numeric', 'min:0'],
        ], [
            'color_id.unique' => 'This variant already has a SKU for that color.',
        ]);

        $sku = new ProductVariantColor($data);
        $sku->product_variant_id = $variant->id;
        $sku->is_active = true;
        $sku->sku = $data['sku'] ?? $this->generateSku($variant, $data['color_id']);

        try {
            $sku->save();
        } catch (QueryException) {
            $sku->sku = $sku->sku.'-'.$variant->id.$data['color_id'];
            $sku->save();
        }

        return new ProductVariantColorResource($sku->load(['variant.model.brand', 'variant.model.productType', 'color']));
    }

    public function update(Request $request, ProductVariantColor $sku)
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:255', 'unique:product_variant_colors,sku,'.$sku->id],
            'barcode' => ['nullable', 'string', 'max:255', 'unique:product_variant_colors,barcode,'.$sku->id],
            'imei_tracking_enabled' => ['nullable', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'selling_price_current' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $sku->update($data);

        return new ProductVariantColorResource($sku->fresh(['variant.model.brand', 'variant.model.productType', 'color']));
    }

    public function destroy(ProductVariantColor $sku)
    {
        try {
            $sku->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This SKU has stock or transaction history and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        return response()->json(status: 204);
    }

    private function generateSku(ProductVariant $variant, int $colorId): string
    {
        $model = $variant->model;
        $colorName = Color::find($colorId)?->name ?? 'COLOR';

        $parts = [$model->brand->name, $model->name, $variant->label(), $colorName];

        return collect($parts)
            ->filter()
            ->map(fn ($part) => Str::upper(Str::slug($part, '')))
            ->implode('-');
    }
}
