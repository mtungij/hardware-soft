<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id',
    'branch_id',
    'name',
    'code',
    'type',
    'description',
    'status',
    'is_default',
    'is_active',
    'can_receive_stock',
    'can_issue_stock',
    'can_sell',
    'is_sellable',
    'can_transfer',
    'can_transfer_to_dispensing',
    'is_dispensing_location',
    'is_warehouse',
    'created_by',
])]
class StockLocation extends Model
{
    use HasCompany, HasFactory, SoftDeletes;

    public const TYPES = [
        'warehouse' => 'Warehouse',
        'store' => 'Store',
        'dispensing' => 'Dispensing',
        'showroom' => 'Showroom',
        'branch_store' => 'Branch Store',
        'returns' => 'Returns',
        'damaged' => 'Damaged',
        'transit' => 'Transit',
        'curing' => 'Curing Yard',
        'quarantine' => 'Quarantine',
        'other' => 'Other',
    ];

    protected static function booted(): void
    {
        $normalize = function (StockLocation $location): void {
            if (in_array($location->type, ['curing', 'quarantine', 'damaged', 'transit'], true)) {
                $location->is_sellable = false;
                $location->can_sell = false;
            }
        };
        static::creating($normalize);
        static::updating($normalize);
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_sellable', true)->where('can_sell', true);
    }

    public function scopeNonSellable(Builder $query): Builder
    {
        return $query->where('is_sellable', false);
    }

    public function scopeCuring(Builder $query): Builder
    {
        return $query->whereIn('type', ['curing', 'quarantine']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_stock_locations')
            ->withPivot(['company_id', 'branch_id', 'can_view', 'can_sell', 'can_transfer', 'can_receive', 'is_default', 'assigned_by'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active && $this->status === 'active';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'can_receive_stock' => 'boolean',
            'can_issue_stock' => 'boolean',
            'can_sell' => 'boolean',
            'is_sellable' => 'boolean',
            'can_transfer' => 'boolean',
            'can_transfer_to_dispensing' => 'boolean',
            'is_dispensing_location' => 'boolean',
            'is_warehouse' => 'boolean',
        ];
    }
}
