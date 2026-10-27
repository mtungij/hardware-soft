<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Models\Concerns\HasPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'goods_receiving_note_id', 'purchase_cost_type_id', 'cost_type_name_snapshot', 'amount', 'payee', 'payment_method', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'payment_submission_key', 'payment_reference', 'notes'])]
class GoodsReceiptAdditionalCost extends Model
{
    use HasCompany, HasPaymentMethod;

    public function goodsReceivingNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivingNote::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
