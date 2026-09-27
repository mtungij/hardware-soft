<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'internal_sale_number', 'sale_date', 'from_location_id', 'to_location_id', 'status', 'total_internal_value', 'created_by', 'completed_by', 'completed_at', 'cancelled_by', 'cancelled_at', 'notes'])]
class InternalSale extends Model
{
    use HasCompany;

    public function items(): HasMany
    {
        return $this->hasMany(InternalSaleItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'total_internal_value' => 'decimal:2',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
