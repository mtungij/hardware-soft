<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'company_id',
    'product_id',
    'stock_location_id',
    'reference_number',
    'adjustment_date',
    'adjustment_type',
    'quantity',
    'reason',
    'notes',
    'approval_comments',
    'status',
    'requested_by',
    'approved_by',
    'approved_at',
    'posted_by',
    'posted_at',
    'reversed_by',
    'reversed_at',
    'reversal_of_id',
])]
class StockAdjustment extends Model
{
    use HasCompany, HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'adjustment_date' => 'date',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
