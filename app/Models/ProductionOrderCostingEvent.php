<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'production_order_costing_id', 'event_type', 'reason', 'snapshot', 'created_by'])]
class ProductionOrderCostingEvent extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Costing events are immutable.'));
        static::deleting(fn () => throw new LogicException('Costing events cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function costing(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderCosting::class, 'production_order_costing_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
