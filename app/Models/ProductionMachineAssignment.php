<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

#[Fillable([
    'company_id', 'branch_id', 'production_method', 'machine_id', 'production_mould_id',
    'production_mould_installation_id', 'product_id', 'production_recipe_id', 'production_date',
    'target_quantity', 'planned_start_time', 'planned_end_time', 'status', 'active_slot_key',
    'notes', 'created_by', 'updated_by',
])]
class ProductionMachineAssignment extends Model
{
    use HasCompany, HasFactory, SoftDeletes;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
        self::STATUS_COMPLETED,
    ];

    public const BLOCKING_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_CONFIRMED,
    ];

    protected static function booted(): void
    {
        static::saving(function (ProductionMachineAssignment $assignment): void {
            $assignment->active_slot_key = self::activeSlotKey(
                (int) $assignment->company_id,
                $assignment->machine_id ? (int) $assignment->machine_id : null,
                $assignment->production_mould_id ? (int) $assignment->production_mould_id : null,
                $assignment->production_date,
                $assignment->status,
            );
        });
        static::updating(function (ProductionMachineAssignment $assignment): void {
            if ($assignment->immutableProductionOrder()) {
                throw new LogicException('Historical production assignments linked to a non-cancelled production order are immutable.');
            }
        });
        static::deleting(function (ProductionMachineAssignment $assignment): void {
            if (! $assignment->isForceDeleting() && $assignment->active_slot_key !== null) {
                $assignment->forceFill(['active_slot_key' => null])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'target_quantity' => 'decimal:4',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mould(): BelongsTo
    {
        return $this->belongsTo(ProductionMould::class, 'production_mould_id');
    }

    public function mouldInstallation(): BelongsTo
    {
        return $this->belongsTo(ProductionMouldInstallation::class, 'production_mould_installation_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductionRecipe::class, 'production_recipe_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function productionOrder(): HasOne
    {
        return $this->hasOne(ProductionOrder::class);
    }

    public function immutableProductionOrder(): ?ProductionOrder
    {
        $order = $this->relationLoaded('productionOrder')
            ? $this->productionOrder
            : $this->productionOrder()->first();

        return $order && ($order->status !== ProductionOrder::STATUS_CANCELLED || $order->posted_at) ? $order : null;
    }

    public function historicalProductionOrder(): ?ProductionOrder
    {
        $order = $this->immutableProductionOrder();

        return $order && ($order->status === ProductionOrder::STATUS_COMPLETED || $order->posted_at)
            ? $order
            : null;
    }

    public function scopeForCurrentCompany(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), CompanyFeatures::companyId());
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::BLOCKING_STATUSES);
    }

    public static function activeSlotKey(int $companyId, ?int $machineId, mixed $mouldIdOrDate, mixed $productionDateOrStatus, ?string $status = null): ?string
    {
        // Keep the original four-argument API for callers representing machine-based work.
        $mouldId = $status === null ? null : ($mouldIdOrDate ? (int) $mouldIdOrDate : null);
        $productionDate = $status === null ? $mouldIdOrDate : $productionDateOrStatus;
        $status ??= (string) $productionDateOrStatus;

        if (! in_array($status, self::BLOCKING_STATUSES, true)) {
            return null;
        }

        $date = $productionDate instanceof \DateTimeInterface
            ? $productionDate->format('Y-m-d')
            : substr((string) $productionDate, 0, 10);

        return $machineId
            ? "{$companyId}:{$machineId}:{$date}"
            : "{$companyId}:mould:{$mouldId}:{$date}";
    }

    public function scopeEligibleForProductionOrder(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('status'), [self::STATUS_PLANNED, self::STATUS_CONFIRMED])
            ->whereDoesntHave('productionOrder')
            ->where(function (Builder $method): void {
                $method->where(function (Builder $machine): void {
                    $machine->where('production_method', ProductFamily::METHOD_MACHINE_MOULD)
                        ->whereHas('machine', fn (Builder $q) => $q->where('status', Machine::STATUS_ACTIVE));
                })->orWhere(function (Builder $mouldOnly): void {
                    $mouldOnly->where('production_method', ProductFamily::METHOD_MOULD_ONLY)->whereNull('machine_id');
                });
            })
            ->whereHas('product', fn (Builder $product) => $product
                ->where('inventory_source', Product::INVENTORY_SOURCE_MANUFACTURED)
                ->where('status', 'active'))
            ->whereHas('recipe', fn (Builder $recipe) => $recipe
                ->where('status', ProductionRecipe::STATUS_ACTIVE)
                ->whereColumn('production_recipes.product_id', $this->qualifyColumn('product_id')))
            ->where(function (Builder $mouldRule): void {
                $mouldRule->where(function (Builder $currentMethod): void {
                    $currentMethod->where('production_method', ProductFamily::METHOD_MACHINE_MOULD)->whereExists(fn ($current) => $current
                        ->selectRaw('1')
                        ->from('production_mould_installations as current_installation')
                        ->join('production_moulds as current_mould', 'current_mould.id', '=', 'current_installation.production_mould_id')
                        ->whereColumn('current_installation.current_machine_id', $this->qualifyColumn('machine_id'))
                        ->whereColumn('current_installation.id', $this->qualifyColumn('production_mould_installation_id'))
                        ->whereColumn('current_mould.id', $this->qualifyColumn('production_mould_id'))
                        ->where('current_mould.active', true)
                        ->where('current_mould.under_maintenance', false)
                        ->whereRaw('current_mould.product_family_id = (select product_family_id from products where products.id = production_machine_assignments.product_id)'));
                })->orWhere(function (Builder $manual): void {
                    $manual->where('production_method', ProductFamily::METHOD_MOULD_ONLY)
                        ->whereHas('mould', fn (Builder $mould) => $mould->available()
                            ->whereRaw('production_moulds.product_family_id = (select product_family_id from products where products.id = production_machine_assignments.product_id)'));
                });
            });
    }
}
