<?php

namespace App\Models;

use App\Support\ManufacturingRoleAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Exceptions\RoleAlreadyExists;

/** Shared roles are templates. Editable permissions belong to a company copy. */
class Role extends \Spatie\Permission\Models\Role
{
    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] ??= 'web';
        $attributes['name'] = \Illuminate\Support\enum_value($attributes['name']);
        if (static::query()->where('name', $attributes['name'])->where('guard_name', $attributes['guard_name'])
            ->where('company_id', $attributes['company_id'] ?? null)->exists()) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    public static function availableForCompany(int $companyId, bool $includeDisabled = false): Collection
    {
        $roles = static::query()->forCompany($companyId)->where('guard_name', 'web')
            ->with('permissions')->withCount(['users' => fn ($query) => $query->where('company_id', $companyId)])
            ->orderByDesc('company_id')->orderBy('name')->get();
        $sources = $roles->pluck('source_role_id')->filter()->all();

        return $roles->reject(fn ($role) => in_array($role->id, $sources, true))
            ->when(! $includeDisabled && ! ManufacturingRoleAccess::enabled($companyId), fn ($roles) => $roles->reject(fn ($role) => ManufacturingRoleAccess::role($role)))
            ->unique('name')->sortBy('name')->values();
    }

    public static function namedForCompany(string $name, int $companyId, string $guard = 'web'): ?self
    {
        return static::query()->forCompany($companyId)->where('name', $name)->where('guard_name', $guard)
            ->orderByDesc('company_id')->first();
    }

    protected static function findByParam(array $params = []): ?RoleContract
    {
        $query = static::query()->where($params);
        $companyId = auth()->user()?->company_id;
        $companyId ? $query->forCompany((int) $companyId) : $query->whereNull('company_id');

        return $query->orderByDesc('company_id')->first();
    }
}
