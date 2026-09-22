<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Http\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        return UserResource::collection(User::with(['branch', 'roles'])->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(Role::pluck('name'))],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $user->assignRole($data['role']);

        return new UserResource($user->load(['branch', 'roles']));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(Role::pluck('name'))],
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
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated.']);
    }
}
