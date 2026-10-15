<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'company_id', 'branch_id', 'stock_location_id', 'opening_date', 'reference_number',
    'notes', 'total_products', 'total_base_quantity', 'total_value', 'created_by', 'posted_at',
])]
class OpeningStock extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException(__('application.posted_opening_stock_cannot_be_edited_0d079c37')));
        static::deleting(fn (): never => throw new LogicException(__('application.posted_opening_stock_cannot_be_deleted_9c5b4483')));
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningStockLine::class);
    }

    protected function casts(): array
    {
        return [
            'opening_date' => 'date',
            'posted_at' => 'datetime',
            'total_base_quantity' => 'decimal:4',
            'total_value' => 'decimal:2',
        ];
    }
}
