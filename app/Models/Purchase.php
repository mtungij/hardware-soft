<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'supplier_id',
    'purchase_date',
    'invoice_number',
    'reference_number',
    'status',
    'payment_status',
    'total_amount',
    'paid_amount',
    'balance_amount',
    'notes',
    'created_by',
    'received_by',
    'received_at',
    'email_sent_at',
    'email_sent_by',
    'email_status',
    'email_recipient',
])]
class Purchase extends Model
{
    use HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function emailSentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email_sent_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function goodsReceivingNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivingNote::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(PurchaseEmailLog::class);
    }

    public function canBeModified(): bool
    {
        return ! in_array($this->status, ['received', 'cancelled'], true)
            && ! $this->items()->where('received_quantity', '>', 0)->exists();
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'received_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
        ];
    }
}
