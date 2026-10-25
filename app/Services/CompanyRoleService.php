<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class CompanyRoleService
{
    public const STANDARD_ROLES = ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Store Keeper', 'Accountant', 'Quality Manager', 'Quality Inspector', 'Production Manager', 'Production Operator'];

    /** Initialize only new company roles. An existing empty role may be an intentional revocation. */
    public function provision(Company $company): Collection
    {
        $roles = DB::transaction(function () use ($company) {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $templates = Role::query()->whereNull('company_id')->where('guard_name', 'web')
                ->whereIn('name', self::STANDARD_ROLES)->with('permissions')->get();

            return $templates->map(function (Role $template) use ($company) {
                $role = Role::query()->where('company_id', $company->id)->where('guard_name', 'web')
                    ->where('source_role_id', $template->id)->first() ?? Role::query()->firstOrCreate([
                        'company_id' => $company->id, 'name' => $template->name, 'guard_name' => 'web',
                    ], $template->only(['sales_scope', 'stock_scope', 'report_scope']) + ['source_role_id' => $template->id]);
                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions($template->permissions->pluck('name')->push('create expenses')->unique()->all());
                }

                return $role;
            });
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $roles;
    }

    public function save(User $actor, ?int $roleId, array $attributes, array $permissions): Role
    {
        abort_unless($actor->company_id && $actor->can('roles.manage'), 403);
        $role = DB::transaction(function () use ($actor, $roleId, $attributes, $permissions) {
            Company::query()->whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $source = $roleId ? Role::query()->forCompany($actor->company_id)->where('guard_name', 'web')->findOrFail($roleId) : null;
            $role = $source;
            if ($source && $source->company_id === null) {
                $role = Role::query()->where('company_id', $actor->company_id)->where('source_role_id', $source->id)->first();
            }
            $ignoredIds = array_filter([$role?->id, $source?->company_id === null ? $source?->id : $source?->source_role_id]);
            if (Role::query()->forCompany($actor->company_id)->where('guard_name', 'web')
                ->where('name', $attributes['name'])->whereNotIn('id', $ignoredIds)->exists()) {
                throw ValidationException::withMessages(['name' => 'This company already has a role with that name.']);
            }
            $role ??= new Role(array_merge($source?->only(['sales_scope', 'stock_scope', 'report_scope']) ?? [], [
                'company_id' => $actor->company_id, 'source_role_id' => $source?->id, 'guard_name' => 'web',
            ]));
            $role->fill(Arr::only($attributes, ['name', 'sales_scope', 'stock_scope', 'report_scope']))->save();
            $role->syncPermissions($permissions);
            if ($source && $source->company_id === null) {
                // Preserve every other role and direct permission; move only this company's assignments.
                $userIds = User::withoutGlobalScopes()->where('company_id', $actor->company_id)->pluck('id');
                $pivot = DB::table('model_has_roles')->where('role_id', $source->id)
                    ->where('model_type', (new User)->getMorphClass())->whereIn('model_id', $userIds);
                foreach ((clone $pivot)->get() as $assignment) {
                    DB::table('model_has_roles')->insertOrIgnore(['role_id' => $role->id, 'model_type' => $assignment->model_type, 'model_id' => $assignment->model_id]);
                }
                $pivot->delete();
            }

            return $role;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->unsetRelation('roles')->unsetRelation('permissions');

        return $role;
    }
}
