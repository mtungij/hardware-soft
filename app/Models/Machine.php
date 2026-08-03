<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'branch_id', 'name', 'code', 'description', 'daily_capacity',
    'capacity_unit', 'status', 'notes', 'created_by', 'updated_by',
])]
class Machine extends Model
{
    use HasCompany, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_MAINTENANCE];

    protected function casts(): array
    {
        return ['daily_capacity' => 'decimal:4'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function dailyAssignments(): HasMany
    {
        return $this->hasMany(ProductionMachineAssignment::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function compatibleMoulds(): BelongsToMany
    {
        return $this->belongsToMany(ProductionMould::class, 'production_machine_mould')
            ->withPivot('company_id')->withTimestamps();
    }

    public function mouldInstallations(): HasMany
    {
        return $this->hasMany(ProductionMouldInstallation::class);
    }

    public function currentMouldInstallation(): HasOne
    {
        return $this->hasOne(ProductionMouldInstallation::class, 'current_machine_id');
    }

    public function latestMouldInstallation(): HasOne
    {
        return $this->hasOne(ProductionMouldInstallation::class)->latestOfMany('installed_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::STATUS_ACTIVE);
    }
}
