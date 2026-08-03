<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id', 'product_family_id', 'code', 'name', 'expected_output_per_cycle',
    'expected_output_per_day', 'active', 'under_maintenance', 'description', 'notes',
    'created_by', 'updated_by',
])]
class ProductionMould extends Model
{
    use HasCompany;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'under_maintenance' => 'boolean',
            'expected_output_per_cycle' => 'decimal:12',
            'expected_output_per_day' => 'decimal:12',
        ];
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('active'), true)
            ->where($this->qualifyColumn('under_maintenance'), false);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function compatibleMachines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class, 'production_machine_mould')
            ->withPivot('company_id')->withTimestamps();
    }

    public function installations(): HasMany
    {
        return $this->hasMany(ProductionMouldInstallation::class);
    }

    public function currentInstallations(): HasMany
    {
        return $this->installations()->whereNotNull('current_machine_id');
    }

    public function currentInstallation(): HasOne
    {
        return $this->hasOne(ProductionMouldInstallation::class, 'current_mould_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductionMachineAssignment::class);
    }

    public function productionOrders(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductionOrder::class,
            ProductionMachineAssignment::class,
            'production_mould_id',
            'production_machine_assignment_id',
            'id',
            'id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
