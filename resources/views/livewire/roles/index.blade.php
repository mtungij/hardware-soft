<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'role_id' => null,
    'name' => '',
    'permissions' => [],
]);

rules([
    'name' => ['required', 'string', 'max:255'],
    'permissions' => ['array'],
    'permissions.*' => ['exists:permissions,name'],
]);

$editRole = function (int $roleId) {
    $role = Role::with('permissions')->findOrFail($roleId);

    $this->role_id = $role->id;
    $this->name = $role->name;
    $this->permissions = $role->permissions->pluck('name')->all();
};

$save = function () {
    $validated = $this->validate();

    $role = Role::query()->updateOrCreate(
        ['id' => $this->role_id],
        ['name' => $validated['name']]
    );

    $role->syncPermissions($validated['permissions']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->reset(['role_id', 'name', 'permissions']);
    session()->flash('success', 'Role saved successfully.');
};

$clearForm = function () {
    $this->reset(['role_id', 'name', 'permissions']);
};

$deleteRole = function (int $roleId) {
    $role = Role::findOrFail($roleId);

    if (in_array($role->name, ['Super Admin', 'Admin'], true) || $role->users()->exists()) {
        session()->flash('error', 'This role cannot be deleted while protected or assigned to users.');
        return;
    }

    $role->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', 'Role deleted.');
};

?>

<div>
    <x-page-header
        title="Roles & Permissions"
        description="Manage Phase 1 access roles and grouped permissions."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Roles' => null]"
    />

    @php
        $roles = Role::withCount('users')->with('permissions')->orderBy('name')->get();
        $permissionGroups = Permission::orderBy('name')->get()->groupBy(fn ($permission) => str($permission->name)->after(' ')->value());
    @endphp

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card title="Role Form" description="Create or edit a role and assign permissions.">
            <form wire:submit="save" class="space-y-4">
                <x-form-input label="Role Name" name="name" wire:model="name" required />

                <div class="space-y-4">
                    @foreach ($permissionGroups as $group => $groupPermissions)
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p class="mb-2 text-sm font-black capitalize">{{ $group }}</p>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($groupPermissions as $permission)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="permissions" value="{{ $permission->name }}" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                                        <span>{{ str($permission->name)->before(' ') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Role</button>
                    <button type="button" wire:click="clearForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Clear</button>
                </div>
            </form>
        </x-card>

        <x-card title="Roles List">
            <x-table :headers="['Role', 'Users', 'Permissions', 'Actions']">
                @foreach ($roles as $role)
                    <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $role->name }}</td>
                        <td class="px-4 py-3">{{ $role->users_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($role->permissions->take(8) as $permission)
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $permission->name }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <button wire:click="editRole({{ $role->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">Edit</button>
                                <button wire:click="deleteRole({{ $role->id }})" wire:confirm="Delete this role?" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
</div>
