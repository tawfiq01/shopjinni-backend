<?php

namespace App\Domain\Branches\Http\Controllers;

use App\Domain\Branches\Http\Resources\BranchResource;
use App\Domain\Branches\Models\Branch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index()
    {
        return BranchResource::collection(Branch::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:branches,name'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $branch = Branch::create([...$data, 'is_main' => false]);

        return new BranchResource($branch);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:branches,name,'.$branch->id],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($data);

        return new BranchResource($branch);
    }
}
