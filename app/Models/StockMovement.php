<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'product_id',
    'stock_location_id',
    'movement_type',
    'quantity',
    'unit_cost',
    'unit_price',
    'reference_type',
    'reference_id',
    'notes',
    'created_by',
    'movement_date',
])]
class StockMovement extends Model
{
    use HasFactory;

    public const POSITIVE_TYPES = ['purchase_in', 'transfer_in', 'adjustment_in', 'return_in', 'direct_stock_in'];

    public const NEGATIVE_TYPES = ['sale_out', 'transfer_out', 'adjustment_out', 'damage_out'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signedQuantity(): float
    {
        return in_array($this->movement_type, self::NEGATIVE_TYPES, true)
            ? -1 * (float) $this->quantity
            : (float) $this->quantity;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }
}
