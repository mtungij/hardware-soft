<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

#[Fillable(['company_id', 'customer_material_account_id', 'branch_id', 'transaction_type', 'reference_number', 'credit_amount', 'debit_amount', 'source_type', 'source_id', 'description', 'notes', 'transacted_at', 'created_by'])]
class CustomerMaterialTransaction extends Model
{
    use HasCompany;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Material-account statement transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Material-account statement transactions cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterialAccount::class, 'customer_material_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['credit_amount' => 'decimal:2', 'debit_amount' => 'decimal:2', 'transacted_at' => 'datetime'];
    }
}
