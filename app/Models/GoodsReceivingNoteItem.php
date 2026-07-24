<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'branch_id',
    'goods_receiving_note_id',
    'purchase_item_id',
    'product_id',
    'purchase_unit_id',
    'stock_unit_id',
    'stock_location_id',
    'ordered_quantity',
    'previously_received_quantity',
    'received_quantity',
    'stock_quantity',
    'cost_price',
    'unit_cost',
    'total_cost',
    'batch_number',
    'expiry_date',
    'notes',
])]
class GoodsReceivingNoteItem extends Model
{
    use HasCompany, HasFactory;

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function sizeLabel(): ?string
    {
        return $this->purchaseItem?->sizeLabel() ?? $this->product?->sizeLabel();
    }

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:4',
            'stock_quantity' => 'decimal:4',
            'cost_price' => 'decimal:2',
            'ordered_quantity' => 'decimal:4',
            'previously_received_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }
}
