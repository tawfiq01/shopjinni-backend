<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductTypeResource;
use App\Domain\Catalog\Models\ProductType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductTypeController extends Controller
{
    public function index()
    {
        return ProductTypeResource::collection(ProductType::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('product_types', 'name')->where('company_id', $request->user()->company_id)->whereNull('deleted_at'),
            ],
            'imei_tracking_default' => ['nullable', 'boolean'],
        ]);

        // Re-adding a name that was previously soft-deleted restores that
        // same row instead of hitting the (company_id, name) unique index.
        $trashed = ProductType::onlyTrashed()->where('name', $data['name'])->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->update([...$data, 'is_active' => true]);

            return new ProductTypeResource($trashed);
        }

        $type = ProductType::create([...$data, 'is_active' => true]);

        return new ProductTypeResource($type);
    }

    public function update(Request $request, ProductType $productType)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('product_types', 'name')->where('company_id', $companyId)->ignore($productType->id)],
            'imei_tracking_default' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $productType->update($data);

        return new ProductTypeResource($productType);
    }

    public function destroy(ProductType $productType)
    {
        if ($productType->models()->exists()) {
            return response()->json([
                'message' => 'This category has models under it and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        $productType->delete();

        return response()->json(status: 204);
    }
}
