<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'role_id' => null,
    'name' => '',
    'permissions' => [],
    'sales_scope' => 'branch',
    'stock_scope' => 'assigned_locations',
    'report_scope' => 'branch',
]);

rules(fn () => [
    'name' => [
        'required',
        'string',
        'max:255',
        Rule::unique('roles', 'name')
            ->where(fn ($query) => $query->where('guard_name', 'web'))
            ->ignore($this->role_id),
    ],
    'permissions' => ['array'],
    'permissions.*' => ['exists:permissions,name'],
    'sales_scope' => ['required', Rule::in(['own', 'branch', 'company'])],
    'stock_scope' => ['required', Rule::in(['assigned_locations', 'branch', 'company'])],
    'report_scope' => ['required', Rule::in(['own', 'branch', 'company'])],
]);

$editRole = function (int $roleId) {
    abort_unless(auth()->user()->can('roles.manage'), 403);
    $role = Role::with('permissions')->findOrFail($roleId);

    $this->role_id = $role->id;
    $this->name = $role->name;
    $this->permissions = $role->permissions->pluck('name')->all();
    $this->sales_scope = $role->sales_scope ?: 'branch';
    $this->stock_scope = $role->stock_scope ?: 'assigned_locations';
    $this->report_scope = $role->report_scope ?: 'branch';
};

$save = function () {
    abort_unless(auth()->user()->can('roles.manage'), 403);
    $validated = $this->validate();
    $validated['permissions'] = $validated['permissions'] ?? [];

    $role = $this->role_id
        ? Role::query()->findOrFail($this->role_id)
        : new Role(['guard_name' => 'web']);

    $role->name = $validated['name'];
    $role->guard_name = 'web';
    $role->sales_scope = $validated['sales_scope'];
    $role->stock_scope = $validated['stock_scope'];
    $role->report_scope = $validated['report_scope'];
    $role->save();

    $role->syncPermissions($validated['permissions']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->reset(['role_id', 'name', 'permissions']);
    $this->sales_scope = 'branch';
    $this->stock_scope = 'assigned_locations';
    $this->report_scope = 'branch';
    session()->flash('success', 'Role saved successfully.');
};

$clearForm = function () {
    $this->reset(['role_id', 'name', 'permissions']);
    $this->sales_scope = 'branch';
    $this->stock_scope = 'assigned_locations';
    $this->report_scope = 'branch';
};

$deleteRole = function (int $roleId) {
    abort_unless(auth()->user()->can('roles.manage'), 403);
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
    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
    @endphp

    <x-page-header
        title="Roles & Permissions"
        description="Manage Phase 1 access roles and grouped permissions."
        :breadcrumbs="['Dashboard' => route('dashboard'), 'Roles' => null]"
    />

    @php
        $roles = Role::withCount('users')->with('permissions')->orderBy('name')->get();
        $sectionFor = function (string $name): string {
            $normalized = str($name)->lower()->toString();

            return match (true) {
                str_contains($normalized, 'dashboard') => 'Dashboard',
                str_contains($normalized, 'sale'), str_contains($normalized, 'pos') => 'Sales / POS',
                str_contains($normalized, 'product'), str_contains($normalized, 'categor'), str_contains($normalized, 'unit') => 'Products',
                str_contains($normalized, 'purchase'), str_contains($normalized, 'supplier') => 'Purchases',
                str_contains($normalized, 'stock'), str_contains($normalized, 'inventory'), str_contains($normalized, 'goods receipt') => 'Warehouse / Stock',
                str_starts_with($normalized, 'production.') => 'Production',
                str_contains($normalized, 'customer') => 'Customers',
                str_contains($normalized, 'accounting'), str_contains($normalized, 'expense'), str_contains($normalized, 'cashbook'), str_contains($normalized, 'profit') => 'Accounting',
                str_contains($normalized, 'report'), str_contains($normalized, 'export'), str_contains($normalized, 'print') => 'Reports',
                str_contains($normalized, 'user'), str_contains($normalized, 'role') => 'Users',
                default => 'Settings & Other',
            };
        };
        $permissionGroups = Permission::orderBy('name')->get()->groupBy(fn ($permission) => $sectionFor($permission->name));
        $permissionLabel = fn (string $name): string => str($name)->contains('.')
            ? str($name)->after('.')->replace('_', ' ')->title()->toString()
            : str($name)->title()->toString();
    @endphp

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card :title="$role_id ? 'Edit Role' : 'Create Role'" description="Create or edit a role and assign permissions.">
            <form wire:submit="save" class="space-y-4">
                <x-form-input label="Role Name" name="name" wire:model="name" required />

                <div class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10">
                    <p class="text-sm font-black">Data Visibility / Scope</p>
                    <div class="mt-3 space-y-3 text-sm">
                        <fieldset><legend class="font-bold">Sales visibility</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['own' => 'Own only', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="sales_scope" value="{{ $value }}"> {{ $label }}</label>@endforeach</div></fieldset>
                        <fieldset><legend class="font-bold">Stock visibility</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['assigned_locations' => 'Assigned locations', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="stock_scope" value="{{ $value }}"> {{ $label }}</label>@endforeach</div></fieldset>
                        <fieldset><legend class="font-bold">Report visibility</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['own' => 'Own', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="report_scope" value="{{ $value }}"> {{ $label }}</label>@endforeach</div></fieldset>
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach ($permissionGroups as $group => $groupPermissions)
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p class="mb-2 text-sm font-black">{{ $group }}</p>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($groupPermissions as $permission)
                                    <label wire:key="permission-{{ $permission->id }}" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="permissions" value="{{ $permission->name }}" class="rounded border-slate-300 text-build-orange focus:ring-build-orange">
                                        <span>{{ $permissionLabel($permission->name) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ $role_id ? $t('Update Role') : $t('Save Role') }}</button>
                    <button type="button" wire:click="clearForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t('Clear') }}</button>
                </div>
            </form>
        </x-card>

        <x-card title="Roles List">
            <x-table :headers="['Role', 'Data Scope', 'Users', 'Permissions', 'Actions']">
                @foreach ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}" class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ $role->name }}</td>
                        <td class="px-4 py-3 text-xs"><div>Sales: {{ str($role->sales_scope)->replace('_', ' ')->title() }}</div><div>Stock: {{ str($role->stock_scope)->replace('_', ' ')->title() }}</div><div>Reports: {{ str($role->report_scope)->replace('_', ' ')->title() }}</div></td>
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
