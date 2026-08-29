<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'customer_id', 'customer_account_id', 'reviewed_by', 'sale_id', 'request_number', 'status', 'submission_key', 'customer_notes', 'staff_notes', 'submitted_at', 'reviewed_at', 'quoted_at', 'accepted_at', 'rejected_at', 'converted_at'])]
class CustomerPurchaseRequest extends Model
{
    use HasCompany;

    public const STATUSES = ['draft', 'pending', 'under_review', 'quoted', 'accepted', 'rejected', 'converted', 'cancelled'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerPurchaseRequestItem::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'quoted_at' => 'datetime',
            'accepted_at' => 'datetime', 'rejected_at' => 'datetime', 'converted_at' => 'datetime',
        ];
    }
}
