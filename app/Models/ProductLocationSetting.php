<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'product_id', 'stock_location_id', 'preferred_receiving_location_id', 'reorder_level', 'reorder_quantity'])]
class ProductLocationSetting extends Model
{
    use HasCompany, HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function preferredReceivingLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'preferred_receiving_location_id');
    }

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:2',
            'reorder_quantity' => 'decimal:2',
        ];
    }
}
