<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'name',
    'phone',
    'email',
    'address',
    'region',
    'customer_type',
    'credit_limit',
    'opening_balance',
    'balance_amount',
    'status',
])]
class Customer extends Model
{
    use HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function portalAccounts(): HasMany
    {
        return $this->hasMany(CustomerAccount::class);
    }

    public function portalReceipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CustomerDeposit::class);
    }

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'balance_amount' => 'decimal:2',
        ];
    }
}
