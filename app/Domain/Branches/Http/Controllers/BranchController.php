<?php

namespace App\Domain\Branches\Http\Controllers;

use App\Domain\Branches\Http\Resources\BranchResource;
use App\Domain\Branches\Models\Branch;
use App\Domain\Subscriptions\Support\SubscriptionLimitGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        return BranchResource::collection(Branch::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('branches', 'name')->where('company_id', $companyId)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        return DB::transaction(function () use ($data, $companyId) {
            SubscriptionLimitGuard::ensure($companyId, 'max_branches', Branch::count(), 'branches');

            $branch = Branch::create([...$data, 'is_main' => false]);

            return new BranchResource($branch);
        });
    }

    public function update(Request $request, Branch $branch)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('branches', 'name')->where('company_id', $companyId)->ignore($branch->id)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branch->update($data);

        return new BranchResource($branch);
    }
}
