<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Models\Concerns\HasPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'supplier_id', 'purchase_id', 'amount', 'payment_method', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'payment_submission_key', 'reference_number', 'payment_date', 'paid_by', 'notes'])]
class SupplierPayment extends Model
{
    use HasCompany, HasFactory, HasPaymentMethod;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }
}
