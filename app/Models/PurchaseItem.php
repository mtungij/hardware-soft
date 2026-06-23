<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_id',
    'product_id',
    'ordered_quantity',
    'received_quantity',
    'cost_price',
    'selling_price',
    'line_total',
])]
class PurchaseItem extends Model
{
    use HasFactory;

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->ordered_quantity - (float) $this->received_quantity);
    }

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:2',
            'received_quantity' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
