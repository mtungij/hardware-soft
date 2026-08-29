<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'type', 'display_name', 'provider', 'bank_name', 'account_name', 'account_number', 'phone_or_business_number', 'branch_name', 'currency', 'instructions', 'is_active', 'sort_order', 'show_on_quotation', 'show_on_proforma', 'show_on_invoice'])]
class CompanyPaymentMethod extends Model
{
    use HasCompany;

    public const TYPES = ['bank', 'mobile_money', 'cash', 'other'];

    public function scopeForDocument(Builder $query, string $documentType): Builder
    {
        $column = match ($documentType) {
            'proforma' => 'show_on_proforma',
            'invoice' => 'show_on_invoice',
            default => 'show_on_quotation',
        };

        return $query->where('is_active', true)->where($column, true)->orderBy('sort_order')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_on_quotation' => 'boolean', 'show_on_proforma' => 'boolean', 'show_on_invoice' => 'boolean', 'sort_order' => 'integer'];
    }
}
