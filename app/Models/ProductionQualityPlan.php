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

#[Fillable(['company_id', 'product_id', 'name', 'code', 'version', 'inspection_stage', 'status', 'effective_from', 'effective_to', 'requires_approval', 'enforce_approval_separation', 'requires_failure_evidence', 'notes', 'created_by', 'updated_by'])]
class ProductionQualityPlan extends Model
{
    use HasCompany;

    public const STAGES = ['raw_material', 'during_production', 'production_completion', 'curing', 'pre_release'];

    public const STATUSES = ['draft', 'active', 'inactive'];

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->getOriginal('status') === 'active') {
                $allowed = ['status', 'updated_by', 'updated_at'];
                if (array_diff(array_keys($plan->getDirty()), $allowed) !== []) {
                    throw new LogicException(__('terminology.active_quality_plans_are_immutable_duplicate_the_plan_to_make_changes_f8403936'));
                }
            }
        });
        static::deleting(function (self $plan): void {
            if ($plan->status !== 'draft' || $plan->inspections()->exists()) {
                throw new LogicException(__('application.historical_or_active_quality_plans_cannot_be_deleted_660a3e32'));
            }
        });
    }

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'requires_approval' => 'boolean', 'enforce_approval_separation' => 'boolean', 'requires_failure_evidence' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ProductionQualityPlanCheck::class)->orderBy('sort_order')->orderBy('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(ProductionQualityInspection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }
}
