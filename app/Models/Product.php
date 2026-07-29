<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Services\ProductImageService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'branch_id',
    'company_id',
    'category_id',
    'measurement_type_id',
    'purchase_unit_id',
    'purchase_conversion_factor',
    'unit_id',
    'product_size_id',
    'uses_product_size',
    'selling_unit_id',
    'name',
    'sku',
    'barcode',
    'brand',
    'model_size',
    'image',
    'image_path',
    'buying_price',
    'selling_price',
    'wholesale_price',
    'conversion_factor',
    'allow_fractional_sale',
    'minimum_sale_quantity',
    'quantity_step',
    'tracks_batch',
    'tracks_expiry',
    'reorder_level',
    'taxable',
    'status',
])]
class Product extends Model
{
    use HasCompany, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            $product->purchase_unit_id ??= $product->unit_id;

            if ((int) $product->purchase_unit_id === (int) $product->unit_id) {
                $product->purchase_conversion_factor = 1;
            }
        });

        static::deleted(function (Product $product): void {
            if (method_exists($product, 'isForceDeleting') && ! $product->isForceDeleting()) {
                return;
            }

            app(ProductImageService::class)->deleteOwned($product->image_path, $product);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function measurementType(): BelongsTo
    {
        return $this->belongsTo(MeasurementType::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
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
        return $this->measurementCode() !== MeasurementType::COUNT
            && ((bool) $this->allow_fractional_sale || (bool) $this->category?->allow_fractional_sales);
    }

    public function measurementCode(): string
    {
        if ($this->measurementType?->code) {
            return $this->measurementType->code;
        }

        if ($this->product_size_id || (bool) $this->allow_fractional_sale || (float) ($this->conversion_factor ?? 1) !== 1.0) {
            return MeasurementType::LENGTH;
        }

        return MeasurementType::COUNT;
    }

    public function allowsDecimalQuantities(): bool
    {
        return $this->measurementCode() !== MeasurementType::COUNT && $this->supportsFractionalSales();
    }

    public function usesUnitConversion(): bool
    {
        return $this->unit_id
            && $this->selling_unit_id
            && (int) $this->unit_id !== (int) $this->selling_unit_id;
    }

    public function saleConversionFactor(): float
    {
        return $this->usesUnitConversion()
            ? max(0.0001, (float) ($this->conversion_factor ?: 1))
            : 1.0;
    }

    public function usesPurchaseConversion(): bool
    {
        return $this->purchase_unit_id
            && $this->unit_id
            && (int) $this->purchase_unit_id !== (int) $this->unit_id;
    }

    public function purchaseConversionFactor(): float
    {
        return $this->usesPurchaseConversion()
            ? max(0.0001, (float) ($this->purchase_conversion_factor ?: 1))
            : 1.0;
    }

    public function stockQuantityForPurchase(float $purchaseQuantity): float
    {
        return round($purchaseQuantity * $this->purchaseConversionFactor(), 4);
    }

    public function acceptsPurchaseQuantity(float $quantity): bool
    {
        $this->loadMissing(['purchaseUnit.measurementType', 'unit.measurementType']);

        return $quantity > 0
            && (($this->purchaseUnit?->measurementType?->code ?? $this->unit?->measurementType?->code ?? $this->measurementCode()) !== MeasurementType::COUNT
                || $this->quantityIsWhole($quantity));
    }

    public function acceptsStockQuantity(float $quantity): bool
    {
        return $quantity > 0
            && ($this->measurementCode() !== MeasurementType::COUNT || $this->quantityIsWhole($quantity));
    }

    public function baseQuantityForSale(float $sellingQuantity): float
    {
        return round($sellingQuantity / $this->saleConversionFactor(), 4);
    }

    public function quantityIsWhole(float $quantity): bool
    {
        return abs($quantity - round($quantity)) <= 0.0001;
    }

    public function quantityFollowsStep(float $quantity): bool
    {
        $minimum = $this->allowsDecimalQuantities() ? (float) ($this->minimum_sale_quantity ?: 1) : 1.0;
        $step = $this->allowsDecimalQuantities() ? (float) ($this->quantity_step ?: 1) : 1.0;

        if ($quantity + 0.0001 < $minimum || $step <= 0) {
            return false;
        }

        $steps = ($quantity - $minimum) / $step;

        return abs($steps - round($steps)) <= 0.0001;
    }

    public function sizeLabel(): ?string
    {
        return $this->size?->label();
    }

    public function usesProductSize(): bool
    {
        return (bool) $this->uses_product_size
            || $this->product_size_id !== null
            || $this->measurementCode() === MeasurementType::LENGTH
            || (bool) $this->category?->supports_product_sizes;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function displayNameWithSize(): string
    {
        return trim($this->displayName().($this->sizeLabel() ? ' - '.$this->sizeLabel() : ''));
    }

    public function getImageUrlAttribute(): string
    {
        if (
            app(ProductImageService::class)->isOwnedPath($this->image_path, $this)
            && Storage::disk('public')->exists($this->image_path)
        ) {
            $diskUrl = Storage::disk('public')->url($this->image_path);
            $relativeUrl = parse_url($diskUrl, PHP_URL_PATH);

            return is_string($relativeUrl) && str_starts_with($relativeUrl, '/')
                ? $relativeUrl
                : '/'.ltrim($diskUrl, '/');
        }

        return '/images/product-placeholder.svg';
    }

    protected function casts(): array
    {
        return [
            'buying_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'conversion_factor' => 'decimal:4',
            'purchase_conversion_factor' => 'decimal:4',
            'allow_fractional_sale' => 'boolean',
            'minimum_sale_quantity' => 'decimal:4',
            'quantity_step' => 'decimal:4',
            'uses_product_size' => 'boolean',
            'tracks_batch' => 'boolean',
            'tracks_expiry' => 'boolean',
            'reorder_level' => 'decimal:2',
            'taxable' => 'boolean',
        ];
    }
}
