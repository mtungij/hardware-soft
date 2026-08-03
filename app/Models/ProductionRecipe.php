<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'product_id', 'active_product_id', 'name', 'code', 'version',
    'output_quantity', 'output_unit_id', 'status', 'effective_from', 'effective_to',
    'notes', 'created_by', 'updated_by',
])]
class ProductionRecipe extends Model
{
    use HasCompany, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected static function booted(): void
    {
        static::saving(function (ProductionRecipe $recipe): void {
            $recipe->active_product_id = $recipe->status === self::STATUS_ACTIVE
                ? $recipe->product_id
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:8',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'output_unit_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionRecipeItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }
}
