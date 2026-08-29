<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'quotation_additional_charge_id', 'additional_charge_type_id', 'charge_name_snapshot', 'description_snapshot', 'amount', 'sort_order'])]
class SaleAdditionalCharge extends Model
{
    use HasCompany;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
