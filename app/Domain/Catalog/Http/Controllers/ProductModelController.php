<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductModelResource;
use App\Domain\Catalog\Models\ProductModel;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ProductModelController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductModel::query()->with(['brand', 'productType']);

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        return ProductModelResource::collection($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'brand_id' => ['required', 'exists:brands,id'],
            'product_type_id' => ['required', 'exists:product_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'warranty_months_default' => ['nullable', 'integer', 'min:0'],
            'imei_tracking_enabled' => ['nullable', 'boolean'],
        ]);

        $model = ProductModel::create([...$data, 'is_active' => true]);
        $model->load(['brand', 'productType']);

        return new ProductModelResource($model);
    }

    public function show(ProductModel $productModel)
    {
        $productModel->load(['brand', 'productType', 'variants.colors.color']);

        return new ProductModelResource($productModel);
    }

    public function update(Request $request, ProductModel $productModel)
    {
        $data = $request->validate([
            'brand_id' => ['sometimes', 'exists:brands,id'],
            'product_type_id' => ['sometimes', 'exists:product_types,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'warranty_months_default' => ['nullable', 'integer', 'min:0'],
            'imei_tracking_enabled' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $productModel->update($data);
        $productModel->load(['brand', 'productType']);

        return new ProductModelResource($productModel);
    }

    public function destroy(ProductModel $productModel)
    {
        try {
            $productModel->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This model has variants under it and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        return response()->json(status: 204);
    }
}
