<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'production_quality_plan_id', 'name', 'description', 'check_type', 'unit_id', 'minimum_value', 'maximum_value', 'target_value', 'allowed_options', 'required', 'critical', 'acceptance_rule', 'sort_order', 'notes'])]
class ProductionQualityPlanCheck extends Model
{
    use HasCompany;

    public const TYPES = ['numeric', 'yes_no', 'visual', 'text', 'selection'];

    public const RULES = ['within_range', 'minimum', 'maximum', 'equals', 'yes_required', 'no_required', 'manual_judgement'];

    protected static function booted(): void
    {
        $guard = function (self $check): void {
            if ($check->plan()->where('status', 'active')->exists()) {
                throw new LogicException('Checks on an active quality plan are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['allowed_options' => 'array', 'required' => 'boolean', 'critical' => 'boolean', 'minimum_value' => 'decimal:8', 'maximum_value' => 'decimal:8', 'target_value' => 'decimal:8'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityPlan::class, 'production_quality_plan_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
