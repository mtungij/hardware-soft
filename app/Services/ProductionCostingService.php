<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Models\ProductionOrderCostingEvent;
use App\Models\ProductionOrderCostingLine;
use App\Models\ProductionOrderMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CompanyFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionCostingService
{
    private const QUANTITY_SCALE = 12;

    private const UNIT_COST_SCALE = 8;

    private const MONEY_SCALE = 4;

    public function calculate(ProductionOrder $order, User $user, ?string $reason = null): ProductionOrderCosting
    {
        abort_unless($user->can('production.manage_costing') && CompanyFeatures::manufacturingEnabled(), 403);

        return DB::transaction(function () use ($order, $user, $reason): ProductionOrderCosting {
            $order = $this->lockedOrder($order, $user);
            if ($order->status !== ProductionOrder::STATUS_COMPLETED || ! $order->posted_at) {
                throw ValidationException::withMessages(['order' => 'Only completed, posted production orders can be costed.']);
            }

            $costing = ProductionOrderCosting::query()->forCurrentCompany()
                ->where('production_order_id', $order->id)->lockForUpdate()->first();
            if ($costing?->status === ProductionOrderCosting::STATUS_FINALIZED) {
                throw ValidationException::withMessages(['costing' => 'Finalized costing cannot be recalculated.']);
            }
            if (! $costing) {
                $costing = ProductionOrderCosting::query()->create([
                    'company_id' => $order->company_id, 'production_order_id' => $order->id,
                    'costing_number' => $this->nextNumber($order), 'currency_code' => $order->product?->company?->currency ?: 'TZS',
                    'planned_quantity' => $order->planned_quantity, 'total_produced_quantity' => $order->total_produced_quantity,
                    'accepted_quantity' => $order->accepted_quantity, 'rejected_quantity' => $order->rejected_quantity,
                ]);
            }

            $existingManual = $costing->lines()->where('is_manual', true)->get()->keyBy('production_order_material_id');
            $order->loadMissing(['materials.materialProduct', 'product.company', 'curingBatch']);
            $movements = StockMovement::query()
                ->where('reference_type', ProductionOrder::class)->where('reference_id', $order->id)
                ->where('movement_type', 'production_consumption')->get()->keyBy('product_id');
            $warnings = [];
            $lineIds = [];
            $plannedInventory = $actualInventory = $plannedNonInventory = $actualNonInventory = '0';

            foreach ($order->materials as $material) {
                $isInventory = $material->line_type === ProductionOrderMaterial::TYPE_INVENTORY;
                $plannedQuantity = $material->planned_quantity;
                $actualQuantity = $material->actual_quantity;
                $plannedUnitCost = $material->unit_cost;
                $plannedTotal = $this->money($material->planned_cost ?: (
                    $plannedQuantity !== null && $plannedUnitCost !== null
                        ? bcmul((string) $plannedQuantity, (string) $plannedUnitCost, self::MONEY_SCALE)
                        : '0'
                ));
                $sourceType = null;
                $sourceId = null;
                $costBasis = 'total_produced';
                $actualUnitCost = null;
                $actualTotal = '0';
                $manual = $existingManual->get($material->id);

                if ($isInventory) {
                    $movement = $movements->get($material->material_product_id);
                    if ($movement && $movement->unit_cost !== null && bccomp((string) $movement->unit_cost, '0', 2) > 0) {
                        $actualUnitCost = $this->unitCost($movement->unit_cost);
                        $sourceType = StockMovement::class;
                        $sourceId = $movement->id;
                    } else {
                        $fallback = $plannedUnitCost ?: $material->materialProduct?->buying_price;
                        $actualUnitCost = filled($fallback) && bccomp((string) $fallback, '0', 4) > 0 ? $this->unitCost($fallback) : null;
                        $sourceType = $actualUnitCost ? 'production_order_material_snapshot' : 'missing';
                        $warnings[] = $actualUnitCost
                            ? "{$material->name}: consumption movement cost missing; order snapshot cost used."
                            : "{$material->name}: no historical material cost is available.";
                    }
                    $actualTotal = $actualQuantity !== null && $actualUnitCost !== null
                        ? $this->money(bcmul((string) $actualQuantity, $actualUnitCost, self::MONEY_SCALE))
                        : '0';
                    $plannedInventory = bcadd($plannedInventory, $plannedTotal, self::MONEY_SCALE);
                    $actualInventory = bcadd($actualInventory, $actualTotal, self::MONEY_SCALE);
                    $costBasis = 'measured_quantity';
                } else {
                    $plannedNonInventory = bcadd($plannedNonInventory, $plannedTotal, self::MONEY_SCALE);
                    if ($manual) {
                        $actualTotal = (string) $manual->actual_total_cost;
                        $actualUnitCost = $manual->actual_unit_cost;
                        $sourceType = 'manual_adjustment';
                        $costBasis = $manual->cost_basis;
                    } elseif ($material->line_type === ProductionOrderMaterial::TYPE_NON_INVENTORY_QUANTITY && $actualQuantity !== null && $plannedUnitCost !== null) {
                        $actualUnitCost = $this->unitCost($plannedUnitCost);
                        $actualTotal = $this->money(bcmul((string) $actualQuantity, $actualUnitCost, self::MONEY_SCALE));
                        $sourceType = 'order_measured_quantity';
                        $costBasis = 'measured_quantity';
                    } elseif ($material->unit_cost !== null) {
                        $actualUnitCost = $this->unitCost($material->unit_cost);
                        $explicitActual = bccomp((string) $material->actual_cost, '0', self::MONEY_SCALE) > 0
                            && bccomp((string) $material->actual_cost, (string) $material->planned_cost, self::MONEY_SCALE) !== 0;
                        $actualTotal = $explicitActual
                            ? $this->money($material->actual_cost)
                            : $this->money(bcmul((string) $order->total_produced_quantity, $actualUnitCost, self::MONEY_SCALE));
                        $sourceType = $explicitActual ? 'order_actual_cost' : 'recipe_snapshot_per_output';
                        $costBasis = $explicitActual ? 'fixed_batch' : 'total_produced';
                    } elseif (bccomp((string) $material->actual_cost, '0', self::MONEY_SCALE) > 0) {
                        $actualTotal = $this->money($material->actual_cost);
                        $sourceType = 'order_actual_cost';
                        $costBasis = 'fixed_batch';
                    } else {
                        $sourceType = 'missing';
                        $warnings[] = "{$material->name}: cost not provided; operational quantity is retained but excluded from totals.";
                    }
                    $actualNonInventory = bcadd($actualNonInventory, $actualTotal, self::MONEY_SCALE);
                }

                $line = ProductionOrderCostingLine::query()->updateOrCreate(
                    ['production_order_material_id' => $material->id],
                    [
                        'company_id' => $order->company_id, 'production_order_costing_id' => $costing->id,
                        'line_type' => $isInventory ? ProductionOrderCostingLine::INVENTORY : $this->nonInventoryType($material->name),
                        'cost_basis' => $costBasis, 'name' => $material->name,
                        'product_id' => $material->material_product_id, 'unit_id' => $material->unit_id,
                        'planned_quantity' => $plannedQuantity, 'actual_quantity' => $actualQuantity,
                        'planned_unit_cost' => $plannedUnitCost, 'actual_unit_cost' => $actualUnitCost,
                        'planned_total_cost' => $plannedTotal, 'actual_total_cost' => $actualTotal,
                        'quantity_variance' => $plannedQuantity !== null && $actualQuantity !== null
                            ? bcsub((string) $actualQuantity, (string) $plannedQuantity, self::QUANTITY_SCALE) : null,
                        'cost_variance' => bcsub($actualTotal, $plannedTotal, self::MONEY_SCALE),
                        'source_type' => $sourceType, 'source_id' => $sourceId,
                        'is_manual' => (bool) $manual, 'notes' => $manual?->notes ?: $material->notes,
                    ]
                );
                $lineIds[] = $line->id;
            }
            $costing->lines()->whereNotIn('id', $lineIds ?: [0])->delete();

            $totalPlanned = bcadd($plannedInventory, $plannedNonInventory, self::MONEY_SCALE);
            $totalActual = bcadd($actualInventory, $actualNonInventory, self::MONEY_SCALE);
            $planned = $this->quantity($order->planned_quantity);
            $produced = $this->quantity($order->total_produced_quantity);
            $accepted = $this->quantity($order->accepted_quantity);
            $rejected = $this->quantity($order->rejected_quantity);
            $damaged = $this->quantity($order->curingBatch?->damaged_quantity ?: 0);
            $released = $this->quantity($order->curingBatch?->released_quantity ?: ($order->product?->requires_curing ? 0 : $accepted));
            $plannedUnit = $this->divide($totalPlanned, $planned);
            $processUnit = $this->divide($totalActual, $produced);
            $acceptedUnit = $this->divide($totalActual, $accepted);
            $eligibleGood = bcsub($accepted, $damaged, self::QUANTITY_SCALE);
            $sellableUnit = $this->divide($totalActual, $eligibleGood);
            $rejectedLoss = $processUnit !== null ? $this->money(bcmul($processUnit, $rejected, self::MONEY_SCALE)) : '0';
            $damageLoss = $acceptedUnit !== null ? $this->money(bcmul($acceptedUnit, $damaged, self::MONEY_SCALE)) : '0';
            $variance = bcsub($totalActual, $totalPlanned, self::MONEY_SCALE);
            $variancePercentage = bccomp($totalPlanned, '0', self::MONEY_SCALE) !== 0
                ? bcmul(bcdiv($variance, $totalPlanned, 8), '100', 4) : null;

            $costing->update([
                'planned_inventory_material_cost' => $plannedInventory, 'actual_inventory_material_cost' => $actualInventory,
                'planned_non_inventory_cost' => $plannedNonInventory, 'actual_non_inventory_cost' => $actualNonInventory,
                'total_planned_cost' => $totalPlanned, 'total_actual_cost' => $totalActual,
                'planned_quantity' => $planned, 'total_produced_quantity' => $produced,
                'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                'curing_damaged_quantity' => $damaged, 'released_quantity' => $released,
                'cost_per_planned_unit' => $plannedUnit, 'cost_per_total_produced_unit' => $processUnit,
                'cost_per_accepted_unit' => $acceptedUnit, 'cost_per_released_unit' => $sellableUnit,
                'rejected_loss_cost' => $rejectedLoss, 'curing_damage_loss_cost' => $damageLoss,
                'total_loss_cost' => bcadd($rejectedLoss, $damageLoss, self::MONEY_SCALE),
                'cost_variance' => $variance, 'variance_percentage' => $variancePercentage,
                'output_variance' => bcsub($produced, $planned, self::QUANTITY_SCALE),
                'yield_variance' => bcsub($accepted, $planned, self::QUANTITY_SCALE),
                'has_missing_cost' => $warnings !== [], 'warnings' => $warnings,
                'status' => ProductionOrderCosting::STATUS_CALCULATED,
                'calculation_version' => $costing->calculation_version + 1,
                'calculated_at' => now(), 'calculated_by' => $user->id,
            ]);
            $this->event($costing, $costing->calculation_version === 1 ? 'calculated' : 'recalculated', $reason, $user);

            return $costing->refresh()->load(['lines.product', 'lines.unit', 'productionOrder.curingBatch']);
        }, 3);
    }

    public function adjustNonInventoryCost(ProductionOrderCostingLine $line, mixed $actualCost, string $reason, User $user): ProductionOrderCosting
    {
        abort_unless($user->can('production.manage_costing') && CompanyFeatures::manufacturingEnabled(), 403);
        $reason = $this->requiredReason($reason);
        $actualCost = $this->nonNegativeMoney($actualCost);

        $order = DB::transaction(function () use ($line, $actualCost, $reason, $user): ProductionOrder {
            $line = ProductionOrderCostingLine::query()->whereKey($line->id)->with('costing.productionOrder')->lockForUpdate()->firstOrFail();
            abort_unless((int) $line->company_id === (int) $user->company_id, 404);
            if ($line->line_type === ProductionOrderCostingLine::INVENTORY || $line->costing->status === ProductionOrderCosting::STATUS_FINALIZED) {
                throw ValidationException::withMessages(['actual_cost' => 'This costing line cannot be manually adjusted.']);
            }
            $line->update([
                'actual_total_cost' => $actualCost,
                'actual_unit_cost' => $line->actual_quantity && bccomp((string) $line->actual_quantity, '0', 12) > 0
                    ? bcdiv($actualCost, (string) $line->actual_quantity, self::UNIT_COST_SCALE) : null,
                'source_type' => 'manual_adjustment', 'is_manual' => true, 'notes' => $reason,
            ]);

            return $line->costing->productionOrder;
        });

        return $this->calculate($order, $user, 'Manual cost adjustment: '.$reason);
    }

    public function finalize(ProductionOrderCosting $costing, User $user, ?string $notes = null): ProductionOrderCosting
    {
        abort_unless($user->can('production.finalize_costing') && CompanyFeatures::manufacturingEnabled(), 403);

        return DB::transaction(function () use ($costing, $user, $notes): ProductionOrderCosting {
            $costing = ProductionOrderCosting::query()->forCurrentCompany()->accessibleTo($user)
                ->whereKey($costing->id)->with('productionOrder.curingBatch')->lockForUpdate()->firstOrFail();
            if ($costing->status === ProductionOrderCosting::STATUS_FINALIZED) {
                return $costing;
            }
            if ($costing->status !== ProductionOrderCosting::STATUS_CALCULATED) {
                throw ValidationException::withMessages(['costing' => 'Calculate costing before finalization.']);
            }
            if ($costing->has_missing_cost) {
                throw ValidationException::withMessages(['costing' => 'Resolve missing cost warnings before finalization.']);
            }
            $batch = $costing->productionOrder?->curingBatch;
            if ($batch && ! in_array($batch->status, [ProductionCuringBatch::STATUS_RELEASED, ProductionCuringBatch::STATUS_CLOSED], true)
                && bccomp((string) $batch->remaining_quantity, '0', self::QUANTITY_SCALE) > 0) {
                throw ValidationException::withMessages(['costing' => 'Curing costing remains provisional until all accepted quantity is released or accounted for as damage.']);
            }
            $costing->update([
                'status' => ProductionOrderCosting::STATUS_FINALIZED, 'finalized_at' => now(),
                'finalized_by' => $user->id, 'notes' => $notes,
            ]);
            $this->event($costing, 'finalized', $notes, $user);

            return $costing->refresh();
        });
    }

    private function lockedOrder(ProductionOrder $order, User $user): ProductionOrder
    {
        abort_unless((int) $order->company_id === (int) $user->company_id, 404);

        return ProductionOrder::query()->forCurrentCompany()->whereKey($order->id)
            ->when($user->branch_id && ! $user->can('manage cross branch stock locations'), fn ($query) => $query->where(fn ($branch) => $branch->where('branch_id', $user->branch_id)->orWhereNull('branch_id')))
            ->with(['materials.materialProduct', 'product.company', 'curingBatch'])->lockForUpdate()->firstOrFail();
    }

    private function nextNumber(ProductionOrder $order): string
    {
        $year = (int) $order->production_date->format('Y');
        DB::table('document_sequences')->insertOrIgnore([
            'company_id' => $order->company_id, 'document_type' => DocumentSequence::PRODUCTION_COSTING,
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DocumentSequence::query()->where('company_id', $order->company_id)
            ->where('document_type', DocumentSequence::PRODUCTION_COSTING)->where('year', $year)
            ->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return 'CST-'.$order->production_date->format('Ymd').'-'.str_pad((string) $sequence->fresh()->last_number, 4, '0', STR_PAD_LEFT);
    }

    private function event(ProductionOrderCosting $costing, string $type, ?string $reason, User $user): void
    {
        ProductionOrderCostingEvent::query()->create([
            'company_id' => $costing->company_id, 'production_order_costing_id' => $costing->id,
            'event_type' => $type, 'reason' => $reason,
            'snapshot' => ['version' => $costing->calculation_version, 'total_actual_cost' => $costing->total_actual_cost, 'status' => $costing->status],
            'created_by' => $user->id,
        ]);
    }

    private function nonInventoryType(string $name): string
    {
        $name = strtolower($name);

        return str_contains($name, 'labour') || str_contains($name, 'labor') ? 'labour'
            : (str_contains($name, 'electric') ? 'electricity'
                : (str_contains($name, 'water') ? 'water' : ProductionOrderCostingLine::NON_INVENTORY));
    }

    private function divide(string $cost, string $quantity): ?string
    {
        return bccomp($quantity, '0', self::QUANTITY_SCALE) > 0
            ? bcdiv($cost, $quantity, self::UNIT_COST_SCALE)
            : null;
    }

    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?: 0), '0', self::QUANTITY_SCALE);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?: 0), '0', self::MONEY_SCALE);
    }

    private function unitCost(mixed $value): string
    {
        return bcadd((string) ($value ?: 0), '0', self::UNIT_COST_SCALE);
    }

    private function nonNegativeMoney(mixed $value): string
    {
        if (! is_numeric($value) || bccomp((string) $value, '0', self::MONEY_SCALE) < 0) {
            throw ValidationException::withMessages(['actual_cost' => 'Actual cost must be zero or greater.']);
        }

        return $this->money($value);
    }

    private function requiredReason(string $reason): string
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for manual cost adjustments.']);
        }

        return trim($reason);
    }
}
