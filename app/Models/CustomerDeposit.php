<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Models\Concerns\HasPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDeposit extends Model
{
    use HasCompany, HasPaymentMethod;

    protected $fillable = [
        'company_id',
        'customer_account_id',
        'customer_id',
        'branch_id',
        'amount',
        'used_amount',
        'balance_amount',
        'payment_method', 'payment_method_id', 'payment_method_name_snapshot', 'payment_method_type_snapshot', 'payment_submission_key',
        'reference_number',
        'receipt_file',
        'notes',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'used_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CustomerDepositUsage::class);
    }
}
