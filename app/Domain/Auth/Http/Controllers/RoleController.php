<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Support\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoleController extends Controller
{
    // Role has no BelongsToCompany-style global scope (it's a spatie
    // model; team-scoping is hand-implemented in specific spatie methods
    // like findByName(), not a query-builder scope) — so listing has to
    // filter by team_id explicitly, and every {role}-bound method has to
    // assertSameCompany() itself, the same way UserController does for
    // User (which has the same "no automatic scope" situation).
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;

        $roles = Role::where('team_id', $companyId)->orderBy('name')->get();

        return response()->json(['data' => $roles->map(fn (Role $role) => $this->formatted($role))]);
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('team_id', $companyId)->where('guard_name', 'web')),
            ],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(PermissionCatalog::ALL)],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions'] ?? []);

        return response()->json($this->formatted($role), 201);
    }

    public function update(Request $request, Role $role)
    {
        $this->assertSameCompany($request, $role);

        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('team_id', $companyId)->where('guard_name', 'web'))->ignore($role->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [Rule::in(PermissionCatalog::ALL)],
        ]);

        if (isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        return response()->json($this->formatted($role->fresh()));
    }

    public function destroy(Request $request, Role $role)
    {
        $this->assertSameCompany($request, $role);

        if ($role->is_system) {
            return response()->json(['message' => 'This role is required and cannot be deleted.'], 409);
        }

        if ($this->userCount($role) > 0) {
            return response()->json([
                'message' => 'This role is assigned to staff members — reassign them before deleting it.',
            ], 409);
        }

        $role->delete();

        return response()->json(status: 204);
    }

    public function permissions()
    {
        return response()->json([
            'data' => collect(PermissionCatalog::ALL)
                ->map(fn (string $key) => ['key' => $key, 'label' => PermissionCatalog::LABELS[$key] ?? $key])
                ->values(),
        ]);
    }

    private function assertSameCompany(Request $request, Role $role): void
    {
        if ($role->team_id !== $request->user()->company_id) {
            throw new NotFoundHttpException();
        }
    }

    private function userCount(Role $role): int
    {
        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->count();
    }

    private function formatted(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_system' => (bool) $role->is_system,
            'permissions' => $role->permissions()->pluck('name'),
            'user_count' => $this->userCount($role),
        ];
    }
}
