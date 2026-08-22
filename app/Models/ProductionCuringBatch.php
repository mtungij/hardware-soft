<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'company_id', 'branch_id', 'production_order_id', 'product_id', 'machine_id', 'production_mould_id',
    'source_stock_location_id', 'default_release_stock_location_id', 'batch_number',
    'production_date', 'curing_started_at', 'minimum_sellable_at', 'full_curing_at',
    'accepted_quantity', 'production_rejected_quantity', 'released_quantity', 'damaged_quantity', 'qc_rejected_quantity', 'release_eligible_quantity', 'remaining_quantity',
    'status', 'qc_approved_at', 'approved_by', 'notes', 'quarantine_reason', 'created_by', 'updated_by', 'closed_by', 'closed_at',
])]
class ProductionCuringBatch extends Model
{
    use HasCompany;

    public const STATUS_CURING = 'curing';

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_READY_FOR_RELEASE = 'ready_for_release';

    public const STATUS_PARTIALLY_RELEASED = 'partially_released';

    public const STATUS_RELEASED = 'released';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_REWORK_REQUIRED = 'rework_required';

    public const STATUS_AWAITING_RETEST = 'awaiting_retest';

    public const STATUS_CLOSED = 'closed';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Curing batches cannot be permanently deleted.'));
    }

    protected function casts(): array
    {
        return [
            'production_date' => 'date', 'curing_started_at' => 'datetime',
            'minimum_sellable_at' => 'datetime', 'full_curing_at' => 'datetime',
            'accepted_quantity' => 'decimal:12', 'released_quantity' => 'decimal:12',
            'production_rejected_quantity' => 'decimal:12',
            'damaged_quantity' => 'decimal:12', 'remaining_quantity' => 'decimal:12',
            'qc_rejected_quantity' => 'decimal:12', 'release_eligible_quantity' => 'decimal:12',
            'qc_approved_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function resolvedStatus(?CarbonInterface $at = null): string
    {
        if (in_array($this->status, [self::STATUS_QUARANTINED, self::STATUS_ON_HOLD, self::STATUS_REWORK_REQUIRED, self::STATUS_AWAITING_RETEST, self::STATUS_CLOSED], true)) {
            return $this->status;
        }
        if (bccomp((string) $this->remaining_quantity, '0', 12) <= 0) {
            return self::STATUS_RELEASED;
        }
        if (bccomp((string) $this->released_quantity, '0', 12) > 0) {
            return self::STATUS_PARTIALLY_RELEASED;
        }
        if ($this->status === self::STATUS_READY_FOR_RELEASE) {
            return self::STATUS_READY_FOR_RELEASE;
        }

        return ($at ?: now($this->company?->timezone ?: config('app.timezone')))->gte($this->minimum_sellable_at)
            ? self::STATUS_ELIGIBLE
            : self::STATUS_CURING;
    }

    public function isEligibleForRelease(?CarbonInterface $at = null): bool
    {
        return ! in_array($this->status, [self::STATUS_QUARANTINED, self::STATUS_ON_HOLD, self::STATUS_REWORK_REQUIRED, self::STATUS_AWAITING_RETEST, self::STATUS_CLOSED], true)
            && bccomp((string) $this->remaining_quantity, '0', 12) > 0
            && ($this->status === self::STATUS_READY_FOR_RELEASE
                || ($at ?: now($this->company?->timezone ?: config('app.timezone')))->gte($this->minimum_sellable_at));
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeAccessibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->branch_id && ! $user->can('manage cross branch stock locations')) {
            $query->where(fn (Builder $branchQuery) => $branchQuery
                ->where('branch_id', $user->branch_id)
                ->orWhereNull('branch_id'));
        }

        return $query;
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId())
            ->accessibleTo(auth()->user());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mould(): BelongsTo
    {
        return $this->belongsTo(ProductionMould::class, 'production_mould_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'source_stock_location_id');
    }

    public function defaultReleaseLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'default_release_stock_location_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(ProductionCuringRelease::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProductionCuringAction::class);
    }

    public function qualityInspections(): HasMany
    {
        return $this->hasMany(ProductionQualityInspection::class);
    }

    public function qualityHolds(): HasMany
    {
        return $this->hasMany(ProductionQualityHold::class);
    }
}
