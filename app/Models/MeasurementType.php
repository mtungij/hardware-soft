<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'sort_order'])]
class MeasurementType extends Model
{
    public const COUNT = 'count';

    public const LENGTH = 'length';

    public const WEIGHT = 'weight';

    public const AREA = 'area';

    public const VOLUME = 'volume';

    public const OTHER = 'other';

    public const CODES = [
        self::COUNT,
        self::LENGTH,
        self::WEIGHT,
        self::AREA,
        self::VOLUME,
        self::OTHER,
    ];

    public $timestamps = false;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function allowsDecimalQuantities(): bool
    {
        return $this->code !== self::COUNT;
    }

    public function usesConversion(): bool
    {
        return $this->code === self::LENGTH;
    }
}
