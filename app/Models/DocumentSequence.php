<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'document_type',
    'year',
    'last_number',
])]
class DocumentSequence extends Model
{
    public const GOODS_RECEIPT = 'goods_receipt';

    public const PRODUCTION_ORDER = 'production_order';

    public const CURING_BATCH = 'curing_batch';

    public const CURING_RELEASE = 'curing_release';

    public const PRODUCTION_COSTING = 'production_costing';

    public const QUALITY_INSPECTION = 'quality_inspection';

    public const QUALITY_HOLD = 'quality_hold';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
