<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ColorResource;
use App\Domain\Catalog\Models\Color;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ColorController extends Controller
{
    public function index()
    {
        return ColorResource::collection(Color::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('colors', 'name')->where('company_id', $request->user()->company_id)->whereNull('deleted_at'),
            ],
            'hex_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        // Re-adding a name that was previously soft-deleted restores that
        // same row instead of hitting the (company_id, name) unique index.
        $trashed = Color::onlyTrashed()->where('name', $data['name'])->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->update($data);

            return new ColorResource($trashed);
        }

        $color = Color::create($data);

        return new ColorResource($color);
    }

    public function update(Request $request, Color $color)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('colors', 'name')->where('company_id', $companyId)->ignore($color->id)],
            'hex_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $color->update($data);

        return new ColorResource($color);
    }

    public function destroy(Color $color)
    {
        if ($color->productVariantColors()->exists()) {
            return response()->json([
                'message' => 'This color is used by one or more SKUs and cannot be deleted.',
            ], 409);
        }

        $color->delete();

        return response()->json(status: 204);
    }
}
