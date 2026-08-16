<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'customer_material_account_id', 'branch_id', 'transaction_type', 'reference_number', 'idempotency_key', 'amount', 'payment_method', 'payment_reference', 'transacted_at', 'notes', 'reason', 'received_by'])]
class CustomerMaterialCashTransaction extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted material-account cash transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted material-account cash transactions cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialAccount::class, 'customer_material_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transacted_at' => 'datetime'];
    }
}
