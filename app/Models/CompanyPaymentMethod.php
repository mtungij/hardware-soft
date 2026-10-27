<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Services\PaymentMethodService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

#[Fillable(['company_id', 'type', 'display_name', 'provider', 'bank_name', 'account_name', 'account_number', 'phone_or_business_number', 'branch_name', 'currency', 'instructions', 'is_active', 'sort_order', 'show_on_quotation', 'show_on_proforma', 'show_on_invoice', 'branch_id', 'is_default', 'legacy_code'])]
class CompanyPaymentMethod extends PaymentMethod
{
    use HasCompany;

    public const TYPES = ['bank', 'mobile_money', 'cash', 'card', 'other'];

    public function scopeForDocument(Builder $query, string $documentType, ?int $branchId = null): Builder
    {
        $column = match ($documentType) {
            'proforma' => 'show_on_proforma',
            'invoice' => 'show_on_invoice',
            default => 'show_on_quotation',
        };

        $branchId ??= PaymentMethodService::context()[1];

        return $query->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))->where('is_active', true)->where($column, true)->orderBy('sort_order')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'show_on_quotation' => 'boolean', 'show_on_proforma' => 'boolean', 'show_on_invoice' => 'boolean', 'sort_order' => 'integer'];
    }
}
