<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_adjustment_id',
    'product_id',
    'system_quantity',
    'physical_quantity',
    'difference_quantity',
    'adjustment_type',
    'reason',
    'notes',
])]
class StockAdjustmentLine extends Model
{
    use HasFactory;

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:2',
            'physical_quantity' => 'decimal:2',
            'difference_quantity' => 'decimal:2',
        ];
    }
}
