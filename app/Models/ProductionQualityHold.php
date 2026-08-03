<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'branch_id', 'production_order_id', 'production_curing_batch_id', 'production_quality_inspection_id', 'product_id', 'hold_number', 'reason', 'status', 'held_quantity', 'placed_at', 'placed_by', 'released_at', 'released_by', 'release_reason', 'notes'])]
class ProductionQualityHold extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(function (self $hold): void {
            if ($hold->getOriginal('status') !== 'active' || array_diff(array_keys($hold->getDirty()), ['status', 'released_at', 'released_by', 'release_reason', 'updated_at']) !== []) {
                throw new LogicException('Quality hold history is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Quality holds cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['held_quantity' => 'decimal:12', 'placed_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'production_quality_inspection_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function curingBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringBatch::class, 'production_curing_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeAccessibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->branch_id && ! $user->can('manage cross branch stock locations')) {
            $query->where(fn (Builder $q) => $q->where('branch_id', $user->branch_id)->orWhereNull('branch_id'));
        }

        return $query;
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId())->accessibleTo(auth()->user());
    }
}
