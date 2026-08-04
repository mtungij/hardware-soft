<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'company_id', 'production_curing_batch_id', 'action_type', 'quantity',
    'reason', 'posting_reference', 'idempotency_key', 'created_by',
])]
class ProductionCuringAction extends Model
{
    use HasCompany;

    public const QUARANTINE = 'quarantine';

    public const UNQUARANTINE = 'unquarantine';

    public const DAMAGE = 'damage';

    public const QC_REJECTION = 'qc_rejection';

    public const CLOSE = 'close';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Curing audit actions are immutable.'));
        static::deleting(fn () => throw new LogicException('Curing audit actions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:12'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringBatch::class, 'production_curing_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
