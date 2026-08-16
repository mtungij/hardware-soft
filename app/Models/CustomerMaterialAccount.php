<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'branch_id', 'customer_id', 'reference_number', 'project_name', 'description', 'project_location', 'status', 'activated_at', 'closed_at', 'created_by', 'updated_by', 'closed_by'])]
class CustomerMaterialAccount extends Model
{
    use HasCompany;

    public const STATUSES = ['draft', 'active', 'completed', 'cancelled', 'closed'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function planLines(): HasMany
    {
        return $this->hasMany(CustomerMaterialPlanLine::class);
    }

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(CustomerMaterialCashTransaction::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(CustomerMaterialIssue::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerMaterialTransaction::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CustomerMaterialAudit::class);
    }

    public function plannedValue(): float
    {
        return (float) $this->planLines()->sum('planned_line_total');
    }

    public function depositedAmount(): float
    {
        return (float) $this->transactions()->sum('credit_amount');
    }

    public function issuedValue(): float
    {
        return (float) $this->transactions()->where('transaction_type', 'material_issue')->sum('debit_amount');
    }

    public function refundedAmount(): float
    {
        return (float) $this->transactions()->where('transaction_type', 'deposit_refund')->sum('debit_amount');
    }

    public function availableFundedBalance(): float
    {
        return round((float) $this->transactions()->sum('credit_amount') - (float) $this->transactions()->sum('debit_amount'), 2);
    }

    public function remainingProjectCommitment(): float
    {
        return max(0, round($this->plannedValue() - $this->issuedValue(), 2));
    }

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
