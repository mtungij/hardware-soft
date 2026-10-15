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
    session()->flash('success', __('application.role_saved_successfully_3c87e59d'));
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
        session()->flash('error', __('application.this_role_cannot_be_deleted_while_protected_or_assigned_to_users_a609818b'));
        return;
    }

    $role->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    session()->flash('success', __('application.role_deleted_88034079'));
};

?>

<div>
    @php
        $t = fn ($value) => \App\Support\UiText::translate($value);
    @endphp

    <x-page-header
        title="{{ __('application.roles_permissions_78f58c62') }}"
        description="{{ __('application.manage_phase_1_access_roles_and_grouped_permissions_811b6754') }}"
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
        $permissionLabel = fn (string $name): string => \App\Support\UiText::permission($name);
    @endphp

    <div class="grid gap-6 xl:grid-cols-[420px_1fr]">
        <x-card :title="$role_id ? __('application.edit_role_b075b676') : __('application.create_role_5bea05a8')" description="{{ __('application.create_or_edit_a_role_and_assign_permissions_759e84cc') }}">
            <form wire:submit="save" class="space-y-4">
                <x-form-input label="{{ __('application.role_name_a8b23a08') }}" name="name" wire:model="name" required />

                <div class="rounded-xl border border-cyan-200 bg-cyan-50/50 p-4 dark:border-cyan-500/30 dark:bg-cyan-500/10">
                    <p class="text-sm font-black">{{ __('application.data_visibility_scope_bd92f94a') }}</p>
                    <div class="mt-3 space-y-3 text-sm">
                        <fieldset><legend class="font-bold">{{ __('application.sales_visibility_2c39118d') }}</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['own' => 'Own only', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="sales_scope" value="{{ $value }}"> {{ __($value === 'own' && $label === 'Own only' ? 'permissions.own_only' : 'permissions.'.$value) }}</label>@endforeach</div></fieldset>
                        <fieldset><legend class="font-bold">{{ __('application.stock_visibility_dfff0daa') }}</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['assigned_locations' => 'Assigned locations', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="stock_scope" value="{{ $value }}"> {{ __($value === 'own' && $label === 'Own only' ? 'permissions.own_only' : 'permissions.'.$value) }}</label>@endforeach</div></fieldset>
                        <fieldset><legend class="font-bold">{{ __('application.report_visibility_d28791c5') }}</legend><div class="mt-1 flex flex-wrap gap-4">@foreach(['own' => 'Own', 'branch' => 'Branch', 'company' => 'Company'] as $value => $label)<label><input type="radio" wire:model="report_scope" value="{{ $value }}"> {{ __($value === 'own' && $label === 'Own only' ? 'permissions.own_only' : 'permissions.'.$value) }}</label>@endforeach</div></fieldset>
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach ($permissionGroups as $group => $groupPermissions)
                        <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <p class="mb-2 text-sm font-black">{{ __('permissions.s_'.substr(sha1($group), 0, 12)) }}</p>
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
                    <button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ $role_id ? $t(__('application.update_role_7d1c623c')) : $t(__('application.save_role_2f46bd88')) }}</button>
                    <button type="button" wire:click="clearForm" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ $t(__('application.clear_719ea396')) }}</button>
                </div>
            </form>
        </x-card>

        <x-card title="{{ __('application.roles_list_11a0593d') }}">
            <x-table :headers="[__('application.role_c3f104d1'), __('terminology.data_scope_f70406fa'), __('application.users_57f2b181'), __('application.permissions_d06d5557'), __('application.actions_c3cd636a')]">
                @foreach ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}" class="hover:bg-slate-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-black">{{ \App\Support\UiText::translate($role->name) }}</td>
                        <td class="px-4 py-3 text-xs"><div>{{ __('application.sales_34a4dd3c') }} {{ \App\Support\UiText::label($role->sales_scope) }}</div><div>{{ __('application.stock_de3ee61a') }} {{ \App\Support\UiText::label($role->stock_scope) }}</div><div>{{ __('application.reports_1b5ae4fb') }} {{ \App\Support\UiText::label($role->report_scope) }}</div></td>
                        <td class="px-4 py-3">{{ $role->users_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($role->permissions->take(8) as $permission)
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ \App\Support\UiText::permission($permission->name) }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <button wire:click="editRole({{ $role->id }})" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold dark:border-slate-700">{{ __('application.edit_5301648d') }}</button>
                                <button wire:click="deleteRole({{ $role->id }})" wire:confirm="{{ __('application.delete_this_role_a6b061eb') }}" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white">{{ __('application.delete_f6fdbe48') }}</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    </div>
</div>
