<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'purchase_item_id', 'purchase_cost_type_id', 'cost_type_name_snapshot', 'amount', 'reference', 'notes'])]
class PurchaseItemCostBreakdown extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->assertPurchaseEditable());
        static::updating(fn (self $row) => $row->assertPurchaseEditable());
        static::deleting(fn (self $row) => $row->assertPurchaseEditable());
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function costType(): BelongsTo
    {
        return $this->belongsTo(PurchaseCostType::class, 'purchase_cost_type_id');
    }

    public function assertPurchaseEditable(): void
    {
        if (! $this->purchaseItem?->purchase?->canBeModified()) {
            throw new LogicException('Received purchase cost breakdowns are immutable.');
        }
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
