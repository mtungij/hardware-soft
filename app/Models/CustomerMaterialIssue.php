<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['company_id', 'customer_material_account_id', 'branch_id', 'stock_location_id', 'reference_number', 'posting_reference', 'idempotency_key', 'total_value', 'total_cost', 'collected_by', 'issued_at', 'notes', 'issued_by'])]
class CustomerMaterialIssue extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted material issues are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted material issues cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialAccount::class, 'customer_material_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerMaterialIssueLine::class);
    }

    protected function casts(): array
    {
        return ['total_value' => 'decimal:2', 'total_cost' => 'decimal:2', 'issued_at' => 'datetime'];
    }
}
