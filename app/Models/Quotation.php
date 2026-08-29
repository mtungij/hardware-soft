<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'branch_id', 'customer_id', 'customer_purchase_request_id', 'created_by', 'converted_sale_id', 'quotation_number', 'document_type', 'source_type', 'creation_key', 'status', 'quotation_date', 'valid_until', 'subtotal', 'discount_amount', 'tax_amount', 'additional_charge_amount', 'total_amount', 'notes', 'terms', 'rejection_reason', 'pdf_path', 'sent_at', 'accepted_at', 'rejected_at'])]
class Quotation extends Model
{
    use HasCompany;

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted', 'cancelled'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerPurchaseRequest::class, 'customer_purchase_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function additionalCharges(): HasMany
    {
        return $this->hasMany(QuotationAdditionalCharge::class)->orderBy('sort_order')->orderBy('id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(SalesInvoice::class);
    }

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime',
            'accepted_at' => 'datetime', 'rejected_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'additional_charge_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
        ];
    }
}
