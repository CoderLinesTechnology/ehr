<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\CreateRole;
use App\Domain\Identity\DeleteRole;
use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\RoleDirectory;
use App\Domain\Identity\RoleGrants;
use App\Domain\Identity\UpdateRolePermissions;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Settings → Roles & Permissions. Roles are data; the permission catalogue is code. A locked role is read-only. */
final class RoleController extends Controller
{
    public function index(RoleDirectory $directory): View
    {
        return view('app.settings.roles.index', [
            'roles' => $directory->overview(tenant()->organizationOrFail()->id),
            'canManage' => Gate::allows('create', Role::class),
        ]);
    }

    public function create(AccessGuard $guard): View
    {
        return view('app.settings.roles.form', $this->formData($guard, null));
    }

    public function store(Request $request, CreateRole $create): RedirectResponse
    {
        $data = $this->validated($request);
        $role = $create($data['name'], $data['description'] ?? null, $data['permissions'] ?? []);

        return redirect()->route('app.settings.roles.index')->with('success', "The role {$role->name} was created.");
    }

    public function edit(Role $role, AccessGuard $guard): View
    {
        return view('app.settings.roles.form', $this->formData($guard, $role));
    }

    public function update(Request $request, Role $role, UpdateRolePermissions $update): RedirectResponse
    {
        $data = $this->validated($request);
        $update($role, $data['name'], $data['description'] ?? null, $data['permissions'] ?? []);

        return redirect()->route('app.settings.roles.index')->with('success', "The role {$role->name} was saved.");
    }

    public function destroy(Role $role, DeleteRole $delete): RedirectResponse
    {
        $name = $role->name;
        $delete($role);

        return redirect()->route('app.settings.roles.index')->with('success', "The role {$name} was deleted.");
    }

    /** @return array{name: string, description?: ?string, permissions?: list<string>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array', 'max:200'],
            'permissions.*' => ['string', 'max:100'],
        ], ['name.required' => 'Give the role a name.']);
    }

    /** @return array<string, mixed> */
    private function formData(AccessGuard $guard, ?Role $role): array
    {
        return [
            'role' => $role,
            'groups' => PermissionRegistry::grouped(PermissionRegistry::SCOPE_ORGANIZATION),
            'held' => $role === null ? [] : ($role->is_locked ? PermissionRegistry::keys(PermissionRegistry::SCOPE_ORGANIZATION) : RoleGrants::current($role)),
            'changeable' => array_flip($guard->manageablePermissions()),
            'readOnly' => $role !== null && Gate::denies('update', $role),
            'canDelete' => $role !== null && Gate::allows('delete', $role),
        ];
    }
}
