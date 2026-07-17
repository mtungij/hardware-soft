<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'branch_id',
    'purchase_id',
    'grn_number',
    'stock_location_id',
    'default_stock_location_id',
    'received_date',
    'supplier_delivery_note_number',
    'supplier_invoice_number',
    'status',
    'received_by',
    'posted_by',
    'posted_at',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
    'notes',
])]
class GoodsReceivingNote extends Model
{
    use HasCompany, HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function defaultStockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'default_stock_location_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivingNoteItem::class);
    }

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
