<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'production_quality_inspection_id', 'production_quality_plan_check_id', 'check_name', 'requirement_snapshot', 'check_type', 'acceptance_rule', 'unit_id', 'unit_snapshot', 'plan_version_snapshot', 'minimum_value', 'maximum_value', 'target_value', 'allowed_options', 'numeric_value', 'boolean_value', 'text_value', 'selected_value', 'result', 'is_required', 'is_critical', 'inspector_comment'])]
class ProductionQualityInspectionResult extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->inspection()->where('approval_status', 'approved')->exists()) {
                throw new LogicException('Approved inspection results are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Inspection results cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['allowed_options' => 'array', 'boolean_value' => 'boolean', 'is_required' => 'boolean', 'is_critical' => 'boolean', 'minimum_value' => 'decimal:8', 'maximum_value' => 'decimal:8', 'target_value' => 'decimal:8', 'numeric_value' => 'decimal:8'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'production_quality_inspection_id');
    }

    public function planCheck(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityPlanCheck::class, 'production_quality_plan_check_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
