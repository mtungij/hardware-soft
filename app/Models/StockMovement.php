<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'company_id',
    'branch_id',
    'product_id',
    'product_unit_conversion_id',
    'transaction_unit_id',
    'transaction_unit_name_snapshot',
    'transaction_unit_code_snapshot',
    'stock_location_id',
    'source_location_id',
    'destination_location_id',
    'movement_type',
    'quantity',
    'quantity_in',
    'quantity_out',
    'transaction_quantity',
    'conversion_factor_snapshot',
    'unit_cost',
    'transaction_unit_cost',
    'unit_price',
    'transaction_unit_price',
    'reference_type',
    'reference_id',
    'production_curing_batch_id',
    'production_curing_release_id',
    'posting_reference',
    'idempotency_key',
    'notes',
    'created_by',
    'movement_date',
])]
class StockMovement extends Model
{
    use HasCompany, HasFactory;

    public const POSITIVE_TYPES = ['purchase_in', 'purchase_receipt', 'transfer_in', 'adjustment_in', 'return_in', 'direct_stock_in', 'production_output', 'curing_release_in'];

    public const NEGATIVE_TYPES = ['sale_out', 'transfer_out', 'adjustment_out', 'damage_out', 'purchase_receipt_reversal', 'production_consumption', 'curing_release_out', 'curing_damage'];

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            if ($movement->production_curing_release_id) {
                throw new LogicException('Posted curing release movements are immutable.');
            }
        });
        static::deleting(function (self $movement): void {
            if ($movement->production_curing_release_id) {
                throw new LogicException('Posted curing release movements cannot be deleted.');
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnitConversion(): BelongsTo
    {
        return $this->belongsTo(ProductUnitConversion::class);
    }

    public function transactionUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'transaction_unit_id');
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'destination_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function curingBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringBatch::class, 'production_curing_batch_id');
    }

    public function curingRelease(): BelongsTo
    {
        return $this->belongsTo(ProductionCuringRelease::class, 'production_curing_release_id');
    }

    public function signedQuantity(): float
    {
        if ((float) $this->quantity_in !== 0.0 || (float) $this->quantity_out !== 0.0) {
            return (float) $this->quantity_in - (float) $this->quantity_out;
        }

        return in_array($this->movement_type, self::NEGATIVE_TYPES, true)
            ? -1 * (float) $this->quantity
            : (float) $this->quantity;
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_in' => 'decimal:4',
            'quantity_out' => 'decimal:4',
            'transaction_quantity' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'transaction_unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'transaction_unit_price' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }
}
