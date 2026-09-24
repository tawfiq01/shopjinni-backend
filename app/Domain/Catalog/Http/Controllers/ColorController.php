<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Http\Resources\ColorResource;
use App\Domain\Catalog\Models\Color;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
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
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('colors', 'name')->where('company_id', $companyId)],
            'hex_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

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
        try {
            $color->delete();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This color is used by one or more SKUs and cannot be deleted.',
            ], 409);
        }

        return response()->json(status: 204);
    }
}
