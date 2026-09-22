<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductVariantResource;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductVariantController extends Controller
{
    public function store(Request $request, ProductModel $productModel)
    {
        $data = $request->validate([
            'ram' => ['nullable', 'string', 'max:50'],
            'storage' => ['nullable', 'string', 'max:50'],
            'extra_spec' => ['nullable', 'string', 'max:255'],
        ]);

        $variant = new ProductVariant($data);
        $variant->product_model_id = $productModel->id;
        $variant->is_active = true;

        try {
            $variant->save();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'ram' => ['This exact RAM/Storage/Spec combination already exists for this model.'],
            ]);
        }

        return new ProductVariantResource($variant);
    }

    public function update(Request $request, ProductVariant $variant)
    {
        $data = $request->validate([
            'ram' => ['nullable', 'string', 'max:50'],
            'storage' => ['nullable', 'string', 'max:50'],
            'extra_spec' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $variant->fill($data);

        try {
            $variant->save();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'ram' => ['This exact RAM/Storage/Spec combination already exists for this model.'],
            ]);
        }

        return new ProductVariantResource($variant->fresh());
    }

    public function destroy(ProductVariant $variant)
    {
        try {
            $variant->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This variant has SKUs under it and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        return response()->json(status: 204);
    }
}
