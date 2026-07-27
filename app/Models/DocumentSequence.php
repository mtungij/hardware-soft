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
