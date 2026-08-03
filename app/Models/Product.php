<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Services\ProductImageService;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
    'inventory_source',
    'product_family_id',
    'requires_curing',
    'curing_days_required',
    'sellable_after_days',
    'curing_notes',
    'requires_quality_control',
    'requires_pre_release_inspection',
    'quality_notes',
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

    public const INVENTORY_SOURCE_PURCHASED = 'purchased';

    public const INVENTORY_SOURCE_MANUFACTURED = 'manufactured';

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            $product->purchase_unit_id ??= $product->unit_id;

            if ((int) $product->purchase_unit_id === (int) $product->unit_id) {
                $product->purchase_conversion_factor = 1;
            }

            $product->normalizeInventorySource();
            $product->normalizeProductFamily();
        });

        static::updating(function (Product $product): void {
            $product->normalizeInventorySource();
            $product->normalizeProductFamily();
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

    public function scopePurchased(Builder $query): Builder
    {
        return $query->where('inventory_source', self::INVENTORY_SOURCE_PURCHASED);
    }

    public function scopeManufactured(Builder $query): Builder
    {
        return $query->where('inventory_source', self::INVENTORY_SOURCE_MANUFACTURED);
    }

    public function isManufactured(): bool
    {
        return $this->inventory_source === self::INVENTORY_SOURCE_MANUFACTURED;
    }

    public function productionMachineAssignments(): HasMany
    {
        return $this->hasMany(ProductionMachineAssignment::class);
    }

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    public function productionRecipes(): HasMany
    {
        return $this->hasMany(ProductionRecipe::class);
    }

    public function recipeMaterialItems(): HasMany
    {
        return $this->hasMany(ProductionRecipeItem::class, 'material_product_id');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function productionCuringBatches(): HasMany
    {
        return $this->hasMany(ProductionCuringBatch::class);
    }

    public function productionQualityPlans(): HasMany
    {
        return $this->hasMany(ProductionQualityPlan::class);
    }

    public function productionQualityInspections(): HasMany
    {
        return $this->hasMany(ProductionQualityInspection::class);
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return CompanyFeatures::manufacturingEnabled()
            ? $query->purchased()
            : $query;
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

    private function normalizeInventorySource(): void
    {
        $company = $this->company_id
            ? Company::query()->find($this->company_id)
            : Company::current();

        if (
            ! $company?->manufacturingEnabled()
            || ! in_array($this->inventory_source, [
                self::INVENTORY_SOURCE_PURCHASED,
                self::INVENTORY_SOURCE_MANUFACTURED,
            ], true)
        ) {
            $this->inventory_source = self::INVENTORY_SOURCE_PURCHASED;
        }

        if ($this->inventory_source !== self::INVENTORY_SOURCE_MANUFACTURED) {
            $this->requires_curing = false;
            $this->curing_days_required = null;
            $this->sellable_after_days = null;
            $this->curing_notes = null;
            $this->requires_quality_control = false;
            $this->requires_pre_release_inspection = false;
            $this->quality_notes = null;
        } elseif ($this->requires_curing) {
            if ((int) $this->curing_days_required <= 0 || (int) $this->sellable_after_days <= 0) {
                throw ValidationException::withMessages([
                    'curing_days_required' => 'Full curing period and minimum sellable age must both be greater than zero.',
                ]);
            }
            if ((int) $this->sellable_after_days > (int) $this->curing_days_required) {
                throw ValidationException::withMessages([
                    'sellable_after_days' => 'Minimum sellable age cannot exceed the full curing period.',
                ]);
            }
        } else {
            $this->curing_days_required = null;
            $this->sellable_after_days = null;
            $this->curing_notes = null;
        }

        if (! $this->requires_quality_control) {
            $this->requires_pre_release_inspection = false;
        }
    }

    private function normalizeProductFamily(): void
    {
        if ($this->inventory_source !== self::INVENTORY_SOURCE_MANUFACTURED) {
            $this->product_family_id = null;

            return;
        }

        if (! $this->company_id) {
            return;
        }

        if (! $this->product_family_id) {
            $this->product_family_id = ProductFamily::defaultForCompany((int) $this->company_id)?->id;

            return;
        }

        $belongsToCompany = ProductFamily::query()->withoutGlobalScopes()
            ->whereKey($this->product_family_id)
            ->where('company_id', $this->company_id)
            ->exists();

        if (! $belongsToCompany) {
            throw ValidationException::withMessages([
                'product_family_id' => 'The selected product family does not belong to this company.',
            ]);
        }
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
            'requires_curing' => 'boolean',
            'requires_quality_control' => 'boolean',
            'requires_pre_release_inspection' => 'boolean',
            'curing_days_required' => 'integer',
            'sellable_after_days' => 'integer',
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
