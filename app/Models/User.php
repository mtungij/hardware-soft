<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['company_id', 'branch_id', 'name', 'email', 'phone', 'profile_photo', 'status', 'sales_location_access', 'is_system_owner', 'password', 'last_login_at', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasCompany, HasFactory, HasRoles, Notifiable;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stockLocations(): BelongsToMany
    {
        return $this->belongsToMany(StockLocation::class, 'user_stock_locations')
            ->withPivot(['company_id', 'branch_id', 'can_view', 'can_sell', 'can_transfer', 'can_receive', 'can_adjust', 'is_default', 'assigned_by'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canAccessPos(): bool
    {
        return $this->hasAnyRole(['Super Admin', 'Admin', 'Manager', 'Cashier']);
    }

    /**
     * @return array<int, string>
     */
    public function allowedSalesLocationTypes(): array
    {
        return match ($this->sales_location_access ?: 'dispensing') {
            'store' => ['store'],
            'both' => ['store', 'dispensing'],
            default => ['dispensing'],
        };
    }

    public function permittedStockLocations(string $ability = 'can_view', ?int $branchId = null): Collection
    {
        $hasExplicitAssignments = $this->stockLocations()
            ->when($branchId, fn ($query) => $query->where(fn ($locations) => $locations
                ->where('stock_locations.branch_id', $branchId)
                ->orWhereNull('stock_locations.branch_id')))
            ->exists();

        $locations = $this->stockLocations()
            ->wherePivot($ability, true)
            ->where('stock_locations.is_active', true)
            ->where('stock_locations.status', 'active')
            ->when($branchId, fn ($query) => $query->where(fn ($locations) => $locations
                ->where('stock_locations.branch_id', $branchId)
                ->orWhereNull('stock_locations.branch_id')))
            ->orderByDesc('user_stock_locations.is_default')
            ->orderBy('stock_locations.name')
            ->get();

        if ($locations->isNotEmpty()) {
            return $locations;
        }

        // Temporary legacy fallback: only used until every existing user has user_stock_locations rows.
        if ($hasExplicitAssignments) {
            return collect();
        }

        if (! $branchId) {
            $branchId = (int) $this->branch_id;
        }

        if (! $branchId) {
            return collect();
        }

        return StockLocation::query()
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->whereIn('type', $this->allowedSalesLocationTypes())
            ->where('status', 'active')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_system_owner' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
