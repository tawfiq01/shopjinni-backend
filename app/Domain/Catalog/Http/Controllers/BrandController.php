<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\BrandResource;
use App\Domain\Catalog\Models\Brand;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index()
    {
        return BrandResource::collection(Brand::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->where('company_id', $companyId)],
        ]);

        $brand = Brand::create([...$data, 'is_active' => true]);

        return new BrandResource($brand);
    }

    public function update(Request $request, Brand $brand)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->where('company_id', $companyId)->ignore($brand->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $brand->update($data);

        return new BrandResource($brand);
    }

    public function destroy(Brand $brand)
    {
        try {
            $brand->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This brand has models under it and cannot be deleted. Deactivate it instead.',
            ], 409);
        }

        return response()->json(status: 204);
    }
}
