<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'customer_id', 'quotation_id', 'source_type', 'invoice_number', 'pdf_path', 'generated_at', 'sent_at'])]
class SalesInvoice extends Model
{
    use HasCompany;

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
