<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'company_id', 'production_curing_batch_id', 'release_number', 'released_quantity',
    'source_stock_location_id', 'destination_stock_location_id', 'released_at',
    'released_by', 'notes', 'posting_reference', 'idempotency_key',
])]
class ProductionCuringRelease extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted curing releases are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted curing releases cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['released_quantity' => 'decimal:12', 'released_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringBatch::class, 'production_curing_batch_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'source_stock_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'destination_stock_location_id');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
