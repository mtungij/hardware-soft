<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'company_id', 'branch_id', 'raw_material_stock_location_id', 'finished_goods_stock_location_id',
    'production_output_stock_location_id', 'final_finished_goods_stock_location_id',
    'production_machine_assignment_id', 'machine_id', 'product_id', 'production_recipe_id',
    'order_number', 'production_date', 'planned_quantity', 'accepted_quantity', 'rejected_quantity',
    'total_produced_quantity', 'status', 'started_at', 'submitted_at', 'completed_at', 'cancelled_at',
    'cancellation_reason', 'notes', 'created_by', 'updated_by', 'started_by', 'completed_by',
    'cancelled_by', 'posted_at', 'posting_reference',
])]
class ProductionOrder extends Model
{
    use HasCompany, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_AWAITING_COMPLETION = 'awaiting_completion';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PLANNED, self::STATUS_IN_PROGRESS,
        self::STATUS_AWAITING_COMPLETION, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    protected static function booted(): void
    {
        static::updating(function (ProductionOrder $order): void {
            if ($order->getOriginal('status') === self::STATUS_COMPLETED) {
                $unsafe = array_diff(array_keys($order->getDirty()), ['notes', 'updated_by', 'updated_at']);
                if ($unsafe !== []) {
                    throw new LogicException('Completed production orders are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'production_date' => 'date', 'planned_quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4', 'rejected_quantity' => 'decimal:4',
            'total_produced_quantity' => 'decimal:4', 'started_at' => 'datetime',
            'submitted_at' => 'datetime', 'completed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProductionMachineAssignment::class, 'production_machine_assignment_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rawMaterialLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'raw_material_stock_location_id');
    }

    public function finishedGoodsLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'finished_goods_stock_location_id');
    }

    public function productionOutputLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'production_output_stock_location_id');
    }

    public function finalFinishedGoodsLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'final_finished_goods_stock_location_id');
    }

    public function curingBatch(): HasOne
    {
        return $this->hasOne(ProductionCuringBatch::class);
    }

    public function costing(): HasOne
    {
        return $this->hasOne(ProductionOrderCosting::class);
    }

    public function qualityInspections(): HasMany
    {
        return $this->hasMany(ProductionQualityInspection::class);
    }

    public function qualityHolds(): HasMany
    {
        return $this->hasMany(ProductionQualityHold::class);
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(ProductionOrderRecipeSnapshot::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderMaterial::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }
}
