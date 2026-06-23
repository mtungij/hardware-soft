<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['branch_id', 'session_date', 'opening_cash', 'cash_sales', 'customer_payments', 'supplier_payments', 'expenses', 'cash_in', 'cash_out', 'expected_cash', 'actual_cash', 'difference', 'status', 'opened_by', 'closed_by', 'closed_at', 'notes'])]
class CashbookSession extends Model
{
    use HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'cash_sales' => 'decimal:2',
            'customer_payments' => 'decimal:2',
            'supplier_payments' => 'decimal:2',
            'expenses' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_out' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }
}
