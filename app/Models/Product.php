<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'branch_id',
    'company_id',
    'category_id',
    'unit_id',
    'product_size_id',
    'selling_unit_id',
    'name',
    'sku',
    'barcode',
    'brand',
    'model_size',
    'image',
    'buying_price',
    'selling_price',
    'wholesale_price',
    'conversion_factor',
    'allow_fractional_sale',
    'minimum_sale_quantity',
    'quantity_step',
    'reorder_level',
    'taxable',
    'status',
])]
class Product extends Model
{
    use HasCompany, HasFactory;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'product_size_id');
    }

    public function sellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function stockTransferItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function supportsFractionalSales(): bool
    {
        return (bool) $this->allow_fractional_sale
            || (bool) $this->category?->allow_fractional_sales;
    }

    public function sizeLabel(): ?string
    {
        return $this->size?->label();
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function displayNameWithSize(): string
    {
        return trim($this->displayName().($this->sizeLabel() ? ' - '.$this->sizeLabel() : ''));
    }

    protected function casts(): array
    {
        return [
            'buying_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'conversion_factor' => 'decimal:4',
            'allow_fractional_sale' => 'boolean',
            'minimum_sale_quantity' => 'decimal:4',
            'quantity_step' => 'decimal:4',
            'reorder_level' => 'decimal:2',
            'taxable' => 'boolean',
        ];
    }
}
