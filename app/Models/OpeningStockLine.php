<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'opening_stock_id', 'product_id', 'product_unit_conversion_id', 'transaction_unit_id',
    'transaction_unit_name_snapshot', 'transaction_unit_code_snapshot', 'transaction_quantity',
    'conversion_factor_snapshot', 'base_quantity', 'unit_cost', 'base_unit_cost', 'total_cost',
    'batch_number', 'expiry_date', 'notes',
])]
class OpeningStockLine extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted Opening Stock lines cannot be edited.'));
        static::deleting(fn (): never => throw new LogicException('Posted Opening Stock lines cannot be deleted.'));
    }

    public function openingStock(): BelongsTo
    {
        return $this->belongsTo(OpeningStock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    protected function casts(): array
    {
        return [
            'transaction_quantity' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'base_unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }
}
