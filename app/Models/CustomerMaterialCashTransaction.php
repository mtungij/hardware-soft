<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Models\Concerns\HasPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['company_id', 'customer_material_account_id', 'branch_id', 'transaction_type', 'reference_number', 'idempotency_key', 'amount', 'payment_method', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'payment_submission_key', 'payment_reference', 'transacted_at', 'notes', 'reason', 'received_by'])]
class CustomerMaterialCashTransaction extends Model
{
    use HasCompany, HasPaymentMethod;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(__('application.posted_material_account_cash_transactions_are_immutable_f9b1cef6')));
        static::deleting(fn () => throw new LogicException(__('application.posted_material_account_cash_transactions_cannot_be_deleted_4a33bef9')));
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
