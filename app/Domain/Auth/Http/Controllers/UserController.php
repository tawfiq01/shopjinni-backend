<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Http\Resources\UserResource;
use App\Domain\Subscriptions\Support\SubscriptionLimitGuard;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserController extends Controller
{
    // User deliberately has no BelongsToCompany global scope (see the
    // model's docblock), so unlike every other controller in the app,
    // this one has to filter by company_id itself — both for listing
    // (index) and for every {user} route-model-bound method, where
    // Laravel's implicit binding would otherwise resolve ANY user in the
    // database, not just ones in the caller's own company.
    public function index(Request $request)
    {
        return UserResource::collection(
            User::where('company_id', $request->user()->company_id)
                ->with(['branch', 'roles'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(Role::where('team_id', $companyId)->pluck('name'))],
        ]);

        return DB::transaction(function () use ($data, $companyId) {
            SubscriptionLimitGuard::ensure(
                $companyId, 'max_users', User::where('company_id', $companyId)->count(), 'staff accounts',
            );

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'company_id' => $companyId,
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);

            $user->assignRole($data['role']);

            return new UserResource($user->load(['branch', 'roles']));
        });
    }

    public function update(Request $request, User $user)
    {
        $this->assertSameCompany($request, $user);
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(Role::where('team_id', $companyId)->pluck('name'))],
        ]);

        if ($user->id === $request->user()->id && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        $user->update(collect($data)->except('role')->toArray());

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return new UserResource($user->fresh(['branch', 'roles']));
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->assertSameCompany($request, $user);

        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated.']);
    }

    private function assertSameCompany(Request $request, User $user): void
    {
        if ($user->company_id !== $request->user()->company_id) {
            throw new NotFoundHttpException();
        }
    }
}
