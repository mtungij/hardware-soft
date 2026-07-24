<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'code', 'measurement_type_id', 'short_name', 'description', 'status'])]
class Unit extends Model
{
    use HasCompany, HasFactory;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sellingProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'selling_unit_id');
    }

    public function purchaseProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'purchase_unit_id');
    }

    public function measurementType(): BelongsTo
    {
        return $this->belongsTo(MeasurementType::class);
    }
}
