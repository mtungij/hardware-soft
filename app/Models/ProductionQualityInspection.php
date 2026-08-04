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

#[Fillable(['company_id', 'branch_id', 'production_quality_plan_id', 'plan_name_snapshot', 'plan_version_snapshot', 'production_order_id', 'production_curing_batch_id', 'recipe_snapshot_id', 'product_id', 'machine_id', 'inspection_number', 'inspection_stage', 'applicable_quantity', 'inspected_quantity', 'sample_quantity', 'passed_quantity', 'failed_quantity', 'result', 'approval_status', 'inspected_at', 'inspected_by', 'approved_at', 'approved_by', 'qc_rejection_applied_at', 'approval_reason', 'rejection_reason', 'reason_justification', 'disposition', 'corrective_action', 'retest_required', 'retest_date', 'supersedes_inspection_id', 'notes'])]
class ProductionQualityInspection extends Model
{
    use HasCompany;

    public const RESULTS = ['pending', 'passed', 'conditional', 'failed', 'hold'];

    public const DISPOSITIONS = ['quarantine', 'rework', 'scrap', 'return_to_curing', 'await_retest', 'other'];

    public const APPROVALS = ['pending', 'approved', 'rejected'];

    protected static function booted(): void
    {
        static::updating(function (self $inspection): void {
            if ($inspection->getOriginal('approval_status') === 'approved') {
                throw new LogicException('Approved quality inspections are immutable; create a retest.');
            }
        });
        static::deleting(fn () => throw new LogicException('Quality inspections are immutable and cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime', 'approved_at' => 'datetime', 'qc_rejection_applied_at' => 'datetime', 'retest_required' => 'boolean', 'retest_date' => 'date', 'applicable_quantity' => 'decimal:12', 'inspected_quantity' => 'decimal:12', 'sample_quantity' => 'decimal:12', 'passed_quantity' => 'decimal:12', 'failed_quantity' => 'decimal:12'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityPlan::class, 'production_quality_plan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function curingBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringBatch::class, 'production_curing_batch_id');
    }

    public function recipeSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderRecipeSnapshot::class, 'recipe_snapshot_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ProductionQualityInspectionResult::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(ProductionQualityHold::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProductionQualityAttachment::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ProductionQualityAuditEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_inspection_id');
    }

    public function retests(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_inspection_id');
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
