<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'company_id', 'name', 'code', 'description', 'icon', 'colour', 'active',
    'default_curing_days', 'default_earliest_release_days', 'default_requires_curing',
    'default_requires_qc', 'default_selling_unit_id', 'default_inventory_unit_id',
])]
class ProductFamily extends Model
{
    use HasCompany;

    public const DEFAULT_CODE = 'concrete-blocks';

    public const ICONS = ['cube', 'grid', 'square', 'pattern', 'curb', 'circle', 'tunnel', 'layers', 'channel', 'shapes'];

    public const COLOURS = ['slate', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'teal', 'emerald', 'amber', 'orange', 'red'];

    public const DEFAULT_DEFINITIONS = [
        ['name' => 'Concrete Blocks', 'code' => 'concrete-blocks', 'icon' => 'cube', 'colour' => 'cyan'],
        ['name' => 'Hollow Blocks', 'code' => 'hollow-blocks', 'icon' => 'grid', 'colour' => 'sky'],
        ['name' => 'Solid Blocks', 'code' => 'solid-blocks', 'icon' => 'square', 'colour' => 'slate'],
        ['name' => 'Paving Blocks', 'code' => 'paving-blocks', 'icon' => 'pattern', 'colour' => 'amber'],
        ['name' => 'Kerbstones', 'code' => 'kerbstones', 'icon' => 'curb', 'colour' => 'orange'],
        ['name' => 'Concrete Pipes', 'code' => 'concrete-pipes', 'icon' => 'circle', 'colour' => 'blue'],
        ['name' => 'Culverts', 'code' => 'culverts', 'icon' => 'tunnel', 'colour' => 'indigo'],
        ['name' => 'Cover Slabs', 'code' => 'cover-slabs', 'icon' => 'layers', 'colour' => 'violet'],
        ['name' => 'Channels', 'code' => 'channels', 'icon' => 'channel', 'colour' => 'teal'],
        ['name' => 'Other Concrete Products', 'code' => 'other-concrete-products', 'icon' => 'shapes', 'colour' => 'slate'],
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'default_requires_curing' => 'boolean',
            'default_requires_qc' => 'boolean',
            'default_curing_days' => 'integer',
            'default_earliest_release_days' => 'integer',
        ];
    }

    public static function ensureDefaultsForCompany(int $companyId): void
    {
        if (static::query()->withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_DEFINITIONS as $definition) {
            DB::table('product_families')->insert([
                'company_id' => $companyId,
                ...$definition,
                'active' => true,
                'default_requires_curing' => false,
                'default_requires_qc' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function defaultForCompany(int $companyId): ?self
    {
        static::ensureDefaultsForCompany($companyId);

        return static::query()->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', self::DEFAULT_CODE)
            ->first()
            ?: static::query()->withoutGlobalScopes()->where('company_id', $companyId)->active()->orderBy('id')->first();
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('active'), true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function moulds(): HasMany
    {
        return $this->hasMany(ProductionMould::class);
    }

    public function defaultSellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_selling_unit_id');
    }

    public function defaultInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_inventory_unit_id');
    }

    public function authoringDefaults(): array
    {
        return [
            'requires_curing' => $this->default_requires_curing,
            'curing_days_required' => $this->default_requires_curing ? $this->default_curing_days : null,
            'sellable_after_days' => $this->default_requires_curing ? $this->default_earliest_release_days : null,
            'requires_quality_control' => $this->default_requires_qc,
            'unit_id' => $this->default_inventory_unit_id,
            'selling_unit_id' => $this->default_selling_unit_id,
        ];
    }
}
