<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_id',
    'company_id',
    'product_id',
    'purchase_unit_id',
    'stock_unit_id',
    'purchase_conversion_factor',
    'product_size_id',
    'ordered_quantity',
    'received_quantity',
    'cost_price',
    'selling_price',
    'line_total',
])]
class PurchaseItem extends Model
{
    use HasCompany, HasFactory;

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    public function purchaseFactor(): float
    {
        if ($this->purchase_unit_id === null && $this->product) {
            return $this->product->purchaseConversionFactor();
        }

        return max(0.0001, (float) ($this->purchase_conversion_factor ?: 1));
    }

    public function stockQuantity(float $purchaseQuantity): float
    {
        return round($purchaseQuantity * $this->purchaseFactor(), 4);
    }

    public function acceptsPurchaseQuantity(float $quantity): bool
    {
        $this->loadMissing(['purchaseUnit.measurementType', 'product.unit.measurementType']);

        return $quantity > 0
            && (($this->purchaseUnit?->measurementType?->code
                    ?? $this->product?->purchaseUnit?->measurementType?->code
                    ?? $this->product?->unit?->measurementType?->code
                    ?? $this->product?->measurementCode()) !== MeasurementType::COUNT
                || $this->product?->quantityIsWhole($quantity));
    }

    public function productSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class);
    }

    public function sizeLabel(): ?string
    {
        return $this->productSize?->label() ?? $this->product?->sizeLabel();
    }

    public function productDisplayNameWithSize(): string
    {
        return trim(($this->product?->displayName() ?? '').($this->sizeLabel() ? ' - '.$this->sizeLabel() : ''));
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->ordered_quantity - (float) $this->received_quantity);
    }

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'purchase_conversion_factor' => 'decimal:4',
        ];
    }
}
