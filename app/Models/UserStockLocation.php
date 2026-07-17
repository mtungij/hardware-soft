<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'user_id', 'stock_location_id', 'can_view', 'can_sell', 'can_transfer', 'can_receive', 'is_default', 'assigned_by'])]
class UserStockLocation extends Model
{
    use HasCompany, HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_sell' => 'boolean',
            'can_transfer' => 'boolean',
            'can_receive' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
