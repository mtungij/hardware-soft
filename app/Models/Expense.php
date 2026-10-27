<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Models\Concerns\HasPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'expense_category_id', 'amount', 'payment_method', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'payment_submission_key', 'reference_number', 'expense_date', 'paid_by', 'notes'])]
class Expense extends Model
{
    use HasCompany, HasFactory, HasPaymentMethod;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }
}
