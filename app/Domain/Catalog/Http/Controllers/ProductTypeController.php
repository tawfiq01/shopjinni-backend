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
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('product_types', 'name')->where('company_id', $companyId)],
            'imei_tracking_default' => ['nullable', 'boolean'],
        ]);

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
}
