<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ProductTypeResource;
use App\Domain\Catalog\Models\ProductType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProductTypeController extends Controller
{
    public function index()
    {
        return ProductTypeResource::collection(ProductType::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_types,name'],
            'imei_tracking_default' => ['nullable', 'boolean'],
        ]);

        $type = ProductType::create([...$data, 'is_active' => true]);

        return new ProductTypeResource($type);
    }

    public function update(Request $request, ProductType $productType)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:product_types,name,'.$productType->id],
            'imei_tracking_default' => ['nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $productType->update($data);

        return new ProductTypeResource($productType);
    }
}
