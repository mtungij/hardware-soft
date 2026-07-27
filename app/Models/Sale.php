<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\InventorySettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'company_id',
    'stock_location_id',
    'customer_id',
    'credit_customer_unassigned',
    'credit_assignment_status',
    'temporary_customer_name',
    'temporary_customer_phone',
    'project_name',
    'vehicle_number',
    'expected_payment_date',
    'credit_notes',
    'credit_assigned_by',
    'credit_assigned_at',
    'credit_assignment_notes',
    'sale_number',
    'idempotency_key',
    'sale_date',
    'sale_type',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total_amount',
    'paid_amount',
    'balance_amount',
    'change_amount',
    'payment_status',
    'status',
    'notes',
    'created_by',
    'sold_by',
    'cancelled_by',
    'cancelled_at',
])]
class Sale extends Model
{
    use HasCompany, HasFactory;

    public function saleType(): string
    {
        if (in_array($this->sale_type, ['retail', 'wholesale'], true)) {
            return $this->sale_type;
        }

        $types = $this->relationLoaded('items')
            ? $this->items->pluck('sale_type')->filter()->unique()
            : $this->items()->pluck('sale_type')->filter()->unique();

        return $types->contains('wholesale') ? 'wholesale' : 'retail';
    }

    public function saleTypeLabel(): string
    {
        return str($this->saleType())->title()->toString();
    }

    public function stockLocationLabel(): string
    {
        $labels = $this->relationLoaded('items')
            ? $this->items->map(fn (SaleItem $item) => $item->sold_from_label ?: ($item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : null))->filter()->unique()->values()
            : $this->items()->with('stockLocation')->get()->map(fn (SaleItem $item) => $item->sold_from_label ?: ($item->stockLocation ? InventorySettings::stockLocationLabel($item->stockLocation) : null))->filter()->unique()->values();

        if ($labels->isEmpty()) {
            return '-';
        }

        return $labels->count() === 1 ? (string) $labels->first() : $labels->join(', ');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customerPaymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'credit_customer_unassigned' => 'boolean',
            'expected_payment_date' => 'date',
            'credit_assigned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }
}
