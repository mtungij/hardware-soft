<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'goods_receiving_note_id', 'purchase_cost_type_id', 'cost_type_name_snapshot', 'amount', 'payee', 'payment_method', 'payment_reference', 'notes'])]
class GoodsReceiptAdditionalCost extends Model
{
    use HasCompany;

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
