<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'quotation_id', 'additional_charge_type_id', 'charge_name_snapshot', 'description_snapshot', 'amount', 'sort_order'])]
class QuotationAdditionalCharge extends Model
{
    use HasCompany;

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function chargeType(): BelongsTo
    {
        return $this->belongsTo(AdditionalChargeType::class, 'additional_charge_type_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
