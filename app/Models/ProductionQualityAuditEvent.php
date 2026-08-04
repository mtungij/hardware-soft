<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'production_quality_inspection_id', 'event_type', 'reference_number', 'previous_state', 'new_state', 'reason', 'created_by', 'occurred_at'])]
class ProductionQualityAuditEvent extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quality audit events are immutable.'));
        static::deleting(fn () => throw new LogicException('Quality audit events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['previous_state' => 'array', 'new_state' => 'array', 'occurred_at' => 'datetime'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(ProductionQualityInspection::class, 'production_quality_inspection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
