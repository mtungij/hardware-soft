<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'company_id', 'production_order_id', 'costing_number', 'currency_code',
    'planned_inventory_material_cost', 'actual_inventory_material_cost',
    'planned_non_inventory_cost', 'actual_non_inventory_cost', 'total_planned_cost',
    'total_actual_cost', 'planned_quantity', 'total_produced_quantity', 'accepted_quantity',
    'rejected_quantity', 'curing_damaged_quantity', 'released_quantity',
    'cost_per_planned_unit', 'cost_per_total_produced_unit', 'cost_per_accepted_unit',
    'cost_per_released_unit', 'rejected_loss_cost', 'curing_damage_loss_cost',
    'total_loss_cost', 'cost_variance', 'variance_percentage', 'output_variance',
    'yield_variance', 'has_missing_cost', 'warnings', 'status', 'calculation_version',
    'calculated_at', 'finalized_at', 'calculated_by', 'finalized_by', 'notes',
])]
class ProductionOrderCosting extends Model
{
    use HasCompany;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_FINALIZED = 'finalized';

    protected static function booted(): void
    {
        static::updating(function (ProductionOrderCosting $costing): void {
            if ($costing->getOriginal('status') === self::STATUS_FINALIZED) {
                throw new LogicException('Finalized production costing is immutable.');
            }
        });
        static::deleting(function (ProductionOrderCosting $costing): void {
            if ($costing->status === self::STATUS_FINALIZED) {
                throw new LogicException('Finalized production costing cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        $money = [
            'planned_inventory_material_cost', 'actual_inventory_material_cost', 'planned_non_inventory_cost',
            'actual_non_inventory_cost', 'total_planned_cost', 'total_actual_cost', 'rejected_loss_cost',
            'curing_damage_loss_cost', 'total_loss_cost', 'cost_variance',
        ];
        $quantity = ['planned_quantity', 'total_produced_quantity', 'accepted_quantity', 'rejected_quantity', 'curing_damaged_quantity', 'released_quantity', 'output_variance', 'yield_variance'];
        $casts = array_fill_keys($money, 'decimal:4') + array_fill_keys($quantity, 'decimal:12');

        return $casts + [
            'cost_per_planned_unit' => 'decimal:8', 'cost_per_total_produced_unit' => 'decimal:8',
            'cost_per_accepted_unit' => 'decimal:8', 'cost_per_released_unit' => 'decimal:8',
            'variance_percentage' => 'decimal:4', 'has_missing_cost' => 'boolean',
            'warnings' => 'array', 'calculation_version' => 'integer',
            'calculated_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeAccessibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->branch_id && ! $user->can('manage cross branch stock locations')) {
            $query->whereHas('productionOrder', fn (Builder $order) => $order
                ->where('branch_id', $user->branch_id)->orWhereNull('branch_id'));
        }

        return $query;
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId())
            ->accessibleTo(auth()->user());
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderCostingLine::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionOrderCostingEvent::class);
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
