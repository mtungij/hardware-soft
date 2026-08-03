<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Machine;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionOrderService
{
    public function __construct(
        private ProductionRecipeCalculator $calculator,
        private InventoryService $inventory,
        private ProductionQualityService $quality,
    ) {}

    /** @param array<string, mixed> $data */
    public function createFromAssignment(ProductionMachineAssignment $assignment, array $data, User $user): ProductionOrder
    {
        abort_unless($user->can('production.create_orders'), 403);
        $companyId = (int) $user->company_id;
        abort_unless((int) $assignment->company_id === $companyId, 404);

        if ($assignment->status === ProductionMachineAssignment::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['assignment' => 'Cancelled assignments cannot create production orders.']);
        }

        $assignment->loadMissing(['machine.currentMouldInstallation.mould', 'product', 'recipe']);
        if (! $assignment->product?->isManufactured()) {
            throw ValidationException::withMessages(['product' => 'Only manufactured products can create production orders.']);
        }

        $planned = $this->positive($data['planned_quantity'] ?? $assignment->target_quantity, 'planned_quantity');
        if ($assignment->production_mould_id) {
            $currentMould = $assignment->machine?->currentMouldInstallation?->mould;
            if (! $currentMould || (int) $currentMould->id !== (int) $assignment->production_mould_id) {
                throw ValidationException::withMessages(['assignment' => __('production.validation.assignment_mould_changed')]);
            }
            if (! $currentMould->active || $currentMould->under_maintenance) {
                throw ValidationException::withMessages(['assignment' => __('production.validation.mould_unavailable')]);
            }
            if (! $currentMould->compatibleMachines()->whereKey($assignment->machine_id)->exists()) {
                throw ValidationException::withMessages(['assignment' => __('production.moulds.validation.not_compatible')]);
            }
            if ((int) $currentMould->product_family_id !== (int) $assignment->product?->product_family_id) {
                throw ValidationException::withMessages(['assignment' => __('production.validation.product_mould_incompatible')]);
            }
        }

        $recipe = ProductionRecipe::query()->forCurrentCompany()
            ->where('product_id', $assignment->product_id)
            ->where('status', ProductionRecipe::STATUS_ACTIVE)
            ->when($assignment->production_recipe_id, fn ($query) => $query->whereKey($assignment->production_recipe_id))
            ->with(['items.materialProduct', 'items.materialUnit'])
            ->first();

        if (! $recipe) {
            throw ValidationException::withMessages(['recipe' => __('production.orders.no_active_recipe')]);
        }

        $branchId = $assignment->branch_id ?: $assignment->machine?->branch_id ?: $user->branch_id;
        $raw = $this->location($data['raw_material_stock_location_id'] ?? null, $companyId, $branchId, true);
        $final = $this->location(
            $data['final_finished_goods_stock_location_id'] ?? $data['finished_goods_stock_location_id'] ?? null,
            $companyId, $branchId, false, true
        );
        $output = $assignment->product->requires_curing
            ? $this->location($data['production_output_stock_location_id'] ?? null, $companyId, $branchId, false, false)
            : $final;

        return DB::transaction(function () use ($assignment, $data, $user, $companyId, $planned, $recipe, $branchId, $raw, $final, $output): ProductionOrder {
            $assignment = ProductionMachineAssignment::query()->forCurrentCompany()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $existing = ProductionOrder::query()->forCurrentCompany()
                ->where('production_machine_assignment_id', $assignment->id)->lockForUpdate()->first();

            if ($existing && $existing->status !== ProductionOrder::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['assignment' => 'This assignment already has a production order.']);
            }
            if ($existing?->posted_at) {
                throw ValidationException::withMessages(['assignment' => 'A posted production order cannot be replaced.']);
            }

            $values = [
                'company_id' => $companyId, 'branch_id' => $branchId,
                'raw_material_stock_location_id' => $raw->id,
                'finished_goods_stock_location_id' => $final->id,
                'production_output_stock_location_id' => $output->id,
                'final_finished_goods_stock_location_id' => $final->id,
                'production_machine_assignment_id' => $assignment->id,
                'machine_id' => $assignment->machine_id, 'product_id' => $assignment->product_id,
                'production_recipe_id' => $recipe->id, 'production_date' => $assignment->production_date,
                'planned_quantity' => $planned, 'accepted_quantity' => 0, 'rejected_quantity' => 0,
                'total_produced_quantity' => 0, 'status' => ProductionOrder::STATUS_PLANNED,
                'notes' => $data['notes'] ?? null, 'updated_by' => $user->id,
                'started_at' => null, 'submitted_at' => null, 'completed_at' => null,
                'cancelled_at' => null, 'cancellation_reason' => null, 'started_by' => null,
                'completed_by' => null, 'cancelled_by' => null, 'posted_at' => null,
                'posting_reference' => null,
            ];

            if ($existing) {
                $existing->update($values);
                $existing->snapshot()->delete();
                $existing->materials()->delete();
                $order = $existing;
            } else {
                $order = ProductionOrder::query()->create([
                    ...$values, 'order_number' => $this->nextOrderNumber($companyId, $assignment->production_date->format('Ymd')),
                    'created_by' => $user->id,
                ]);
            }

            $order->snapshot()->create([
                'company_id' => $companyId, 'source_recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name, 'recipe_code' => $recipe->code,
                'recipe_version' => $recipe->version, 'recipe_output_quantity' => $recipe->output_quantity,
                'recipe_output_unit_id' => $recipe->output_unit_id, 'captured_at' => now(),
            ]);

            foreach ($recipe->items as $index => $item) {
                $normalized = $item->normalized_quantity !== null ? (string) $item->normalized_quantity : null;
                $plannedQuantity = $normalized !== null
                    ? bcmul($normalized, $planned, ProductionRecipeCalculator::QUANTITY_SCALE) : null;
                $lineType = $item->cost_type === ProductionRecipeItem::TYPE_INVENTORY
                    ? ProductionOrderMaterial::TYPE_INVENTORY
                    : ($normalized !== null ? ProductionOrderMaterial::TYPE_NON_INVENTORY_QUANTITY : ProductionOrderMaterial::TYPE_NON_INVENTORY_COST);
                $plannedUnitCost = $lineType === ProductionOrderMaterial::TYPE_INVENTORY
                    ? (string) ($item->materialProduct?->buying_price ?: 0)
                    : ($item->unit_cost !== null ? (string) $item->unit_cost : null);
                $plannedCost = $plannedUnitCost !== null
                    ? bcmul(
                        $plannedUnitCost,
                        in_array($lineType, [ProductionOrderMaterial::TYPE_INVENTORY, ProductionOrderMaterial::TYPE_NON_INVENTORY_QUANTITY], true)
                            ? ($plannedQuantity ?: '0')
                            : $planned,
                        ProductionRecipeCalculator::COST_SCALE
                    )
                    : '0';

                $order->materials()->create([
                    'company_id' => $companyId, 'source_recipe_item_id' => $item->id,
                    'line_type' => $lineType, 'material_product_id' => $item->material_product_id,
                    'name' => $item->materialProduct?->name ?: $item->cost_name,
                    'unit_id' => $item->material_unit_id, 'normalized_quantity_per_output' => $normalized,
                    'planned_quantity' => $plannedQuantity, 'actual_quantity' => null,
                    'unit_cost' => $plannedUnitCost, 'planned_cost' => $plannedCost, 'actual_cost' => 0,
                    'entry_mode' => $item->entry_mode, 'source_quantity' => $item->source_quantity,
                    'source_yield_quantity' => $item->yield_quantity, 'notes' => $item->notes, 'sort_order' => $index,
                ]);
            }

            return $order->load(['snapshot', 'materials.materialProduct', 'materials.unit', 'machine', 'product']);
        });
    }

    public function start(ProductionOrder $order, User $user): ProductionOrder
    {
        abort_unless($user->can('production.execute_orders'), 403);

        return DB::transaction(function () use ($order, $user): ProductionOrder {
            $order = $this->locked($order, $user);
            if ($order->status !== ProductionOrder::STATUS_PLANNED) {
                throw ValidationException::withMessages(['status' => 'Only planned orders can be started.']);
            }
            if (! $order->snapshot()->exists() || ! $order->production_recipe_id || ! $order->materials()->exists()) {
                throw ValidationException::withMessages(['snapshot' => 'An immutable active-recipe snapshot is required before production can start.']);
            }
            $machine = Machine::query()->forCurrentCompany()->whereKey($order->machine_id)->lockForUpdate()->firstOrFail();
            if ($machine->status !== Machine::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['machine' => 'The machine must be active before production starts.']);
            }
            $raw = $this->lockedLocation($order->raw_material_stock_location_id, $order, $user, true);
            $inventoryLines = $order->materials()->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY)->lockForUpdate()->get();
            $this->validateAvailableInventory($inventoryLines, $raw->id, 'planned_quantity');

            $order->materials()->get()->each(fn (ProductionOrderMaterial $line) => $line->update([
                'actual_quantity' => $line->planned_quantity, 'actual_cost' => $line->planned_cost,
            ]));
            $order->update(['status' => ProductionOrder::STATUS_IN_PROGRESS, 'started_at' => now(), 'started_by' => $user->id, 'updated_by' => $user->id]);

            return $order->refresh()->load('materials');
        });
    }

    /** @param array<int|string, array<string, mixed>> $materials */
    public function saveExecution(ProductionOrder $order, array $materials, mixed $accepted, mixed $rejected, ?string $notes, User $user): ProductionOrder
    {
        abort_unless($user->can('production.execute_orders'), 403);

        return DB::transaction(function () use ($order, $materials, $accepted, $rejected, $notes, $user): ProductionOrder {
            $order = $this->locked($order, $user);
            if ($order->status !== ProductionOrder::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages(['status' => 'Only in-progress orders can record execution.']);
            }
            $acceptedValue = $this->nonNegative($accepted, 'accepted_quantity', 4);
            $rejectedValue = $this->nonNegative($rejected, 'rejected_quantity', 4);
            $totalProduced = bcadd($acceptedValue, $rejectedValue, 4);
            if (bccomp($totalProduced, (string) $order->planned_quantity, 4) > 0) {
                throw ValidationException::withMessages([
                    'accepted_quantity' => 'Accepted plus rejected quantity cannot exceed the planned practical output.',
                    'rejected_quantity' => 'Accepted plus rejected quantity cannot exceed the planned practical output.',
                ]);
            }
            $lines = $order->materials()->lockForUpdate()->get();
            foreach ($lines as $line) {
                $input = $materials[$line->id] ?? [];
                $actualQuantity = filled($input['actual_quantity'] ?? null)
                    ? $this->nonNegative($input['actual_quantity'], "materials.{$line->id}.actual_quantity")
                    : null;
                $actualCost = filled($input['actual_cost'] ?? null)
                    ? $this->nonNegative($input['actual_cost'], "materials.{$line->id}.actual_cost", 4)
                    : '0';
                if ($line->line_type === ProductionOrderMaterial::TYPE_INVENTORY && $actualQuantity === null) {
                    throw ValidationException::withMessages(["materials.{$line->id}.actual_quantity" => 'Actual inventory consumption is required.']);
                }
                $line->update(['actual_quantity' => $actualQuantity, 'actual_cost' => $actualCost]);
            }
            $raw = $this->lockedLocation($order->raw_material_stock_location_id, $order, $user, true);
            $this->validateAvailableInventory(
                $lines->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY),
                $raw->id,
                'actual_quantity'
            );
            $order->update([
                'accepted_quantity' => $acceptedValue, 'rejected_quantity' => $rejectedValue,
                'total_produced_quantity' => $totalProduced,
                'notes' => $notes, 'updated_by' => $user->id,
            ]);

            return $order->refresh()->load('materials');
        });
    }

    public function submit(ProductionOrder $order, User $user): ProductionOrder
    {
        abort_unless($user->can('production.execute_orders'), 403);

        return DB::transaction(function () use ($order, $user): ProductionOrder {
            $order = $this->locked($order, $user);
            if ($order->status !== ProductionOrder::STATUS_IN_PROGRESS || bccomp((string) $order->total_produced_quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages(['output' => 'Record accepted or rejected output before submission.']);
            }
            foreach ($order->materials()->get() as $line) {
                if ($line->line_type === ProductionOrderMaterial::TYPE_INVENTORY && $line->actual_quantity === null) {
                    throw ValidationException::withMessages(['materials' => 'Record actual inventory consumption before submission.']);
                }
            }
            $order->update(['status' => ProductionOrder::STATUS_AWAITING_COMPLETION, 'submitted_at' => now(), 'updated_by' => $user->id]);

            return $order->refresh();
        });
    }

    /** @param array<int|string, array<string, mixed>> $materials */
    public function completeExecution(
        ProductionOrder $order,
        array $materials,
        mixed $accepted,
        mixed $rejected,
        ?string $notes,
        User $user
    ): ProductionOrder {
        abort_unless($user->can('production.execute_orders') && $user->can('production.complete_orders'), 403);

        return DB::transaction(function () use ($order, $materials, $accepted, $rejected, $notes, $user): ProductionOrder {
            $order = $this->saveExecution($order, $materials, $accepted, $rejected, $notes, $user);
            $order = $this->submit($order, $user);

            return $this->complete($order, $user);
        }, 3);
    }

    public function complete(ProductionOrder $order, User $user): ProductionOrder
    {
        abort_unless($user->can('production.complete_orders'), 403);

        return DB::transaction(function () use ($order, $user): ProductionOrder {
            $order = $this->locked($order, $user);
            if ($order->status === ProductionOrder::STATUS_COMPLETED && $order->posted_at) {
                return $order;
            }
            if ($order->status !== ProductionOrder::STATUS_AWAITING_COMPLETION) {
                throw ValidationException::withMessages(['status' => 'Only orders awaiting completion can be posted.']);
            }
            $order->loadMissing('product.company');
            $raw = $this->lockedLocation($order->raw_material_stock_location_id, $order, $user, true);
            $outputLocationId = $order->production_output_stock_location_id ?: $order->finished_goods_stock_location_id;
            $output = $this->lockedLocation($outputLocationId, $order, $user, false);
            $requiresCuring = (bool) $order->product?->requires_curing;
            if ($requiresCuring && ($output->is_sellable || ! in_array($output->type, ['curing', 'quarantine'], true))) {
                throw ValidationException::withMessages(['location' => 'A curing product must post to an active non-sellable curing location.']);
            }
            if (! $requiresCuring && ! $output->is_sellable) {
                throw ValidationException::withMessages(['location' => 'A non-curing product must post to a sellable finished-goods location.']);
            }
            $lines = $order->materials()->with('materialProduct')->lockForUpdate()->get();
            $postingReference = 'PRDPOST-'.$order->order_number;

            foreach ($lines->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY) as $line) {
                $quantity = $this->ledgerQuantity((string) $line->actual_quantity);
                StockMovement::query()->where('product_id', $line->material_product_id)
                    ->where('stock_location_id', $raw->id)->lockForUpdate()->get();
                $available = $this->availableStock($line->material_product_id, $raw->id);
                if (bccomp($available, $quantity, 4) < 0) {
                    throw ValidationException::withMessages([
                        "materials.{$line->id}" => "{$line->name} is short. Required {$quantity}; available {$available}.",
                    ]);
                }
            }

            foreach ($lines->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY) as $line) {
                $quantity = $this->ledgerQuantity((string) $line->actual_quantity);
                $historicalCost = $this->inventory->getAverageCost($line->material_product_id, $raw->id, $order->branch_id);
                if ($historicalCost <= 0) {
                    $historicalCost = (float) ($line->materialProduct?->buying_price ?: 0);
                }
                StockMovement::query()->create([
                    'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
                    'product_id' => $line->material_product_id, 'stock_location_id' => $raw->id,
                    'source_location_id' => $raw->id, 'movement_type' => 'production_consumption',
                    'quantity' => $quantity, 'quantity_in' => 0, 'quantity_out' => $quantity,
                    'unit_cost' => $historicalCost,
                    'reference_type' => ProductionOrder::class, 'reference_id' => $order->id,
                    'notes' => "{$postingReference} / {$line->name}", 'created_by' => $user->id,
                    'movement_date' => $order->production_date,
                ]);
            }

            $accepted = $this->ledgerQuantity((string) $order->accepted_quantity);
            if (bccomp($accepted, '0', 4) > 0) {
                $curingBatch = null;
                if ($requiresCuring) {
                    $timezone = $order->product?->company?->timezone ?: config('app.timezone');
                    $startedAt = CarbonImmutable::parse($order->production_date->toDateString(), $timezone)->startOfDay();
                    $curingBatch = ProductionCuringBatch::query()->create([
                        'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
                        'production_order_id' => $order->id, 'product_id' => $order->product_id,
                        'machine_id' => $order->machine_id, 'source_stock_location_id' => $output->id,
                        'default_release_stock_location_id' => $order->final_finished_goods_stock_location_id ?: $order->finished_goods_stock_location_id,
                        'batch_number' => 'CUR-'.$order->order_number, 'production_date' => $order->production_date,
                        'curing_started_at' => $startedAt,
                        'minimum_sellable_at' => $startedAt->addDays((int) $order->product->sellable_after_days),
                        'full_curing_at' => $startedAt->addDays((int) $order->product->curing_days_required),
                        'accepted_quantity' => $accepted, 'remaining_quantity' => $accepted,
                        'status' => ProductionCuringBatch::STATUS_CURING,
                        'notes' => $order->product->curing_notes, 'created_by' => $user->id, 'updated_by' => $user->id,
                    ]);
                    $this->quality->queueCuringInspection($order, $curingBatch, $user);
                }
                StockMovement::query()->create([
                    'company_id' => $order->company_id, 'branch_id' => $order->branch_id,
                    'product_id' => $order->product_id, 'stock_location_id' => $output->id,
                    'destination_location_id' => $output->id, 'movement_type' => 'production_output',
                    'quantity' => $accepted, 'quantity_in' => $accepted, 'quantity_out' => 0,
                    'reference_type' => ProductionOrder::class, 'reference_id' => $order->id,
                    'production_curing_batch_id' => $curingBatch?->id,
                    'posting_reference' => $postingReference,
                    'notes' => $postingReference, 'created_by' => $user->id,
                    'movement_date' => $order->production_date,
                ]);
            }

            $order->update([
                'status' => ProductionOrder::STATUS_COMPLETED, 'completed_at' => now(),
                'completed_by' => $user->id, 'posted_at' => now(),
                'posting_reference' => $postingReference, 'updated_by' => $user->id,
            ]);

            return $order->refresh();
        }, 3);
    }

    public function cancel(ProductionOrder $order, string $reason, User $user): ProductionOrder
    {
        abort_unless($user->can('production.cancel_orders'), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['cancellation_reason' => 'Cancellation reason is required.']);
        }

        return DB::transaction(function () use ($order, $reason, $user): ProductionOrder {
            $order = $this->locked($order, $user);
            if (in_array($order->status, [ProductionOrder::STATUS_COMPLETED, ProductionOrder::STATUS_CANCELLED], true) || $order->posted_at) {
                throw ValidationException::withMessages(['status' => 'This production order cannot be cancelled.']);
            }
            $order->update([
                'status' => ProductionOrder::STATUS_CANCELLED, 'cancelled_at' => now(),
                'cancelled_by' => $user->id, 'cancellation_reason' => trim($reason), 'updated_by' => $user->id,
            ]);

            return $order->refresh();
        });
    }

    public function availability(ProductionOrder $order): array
    {
        return $order->materials()->where('line_type', ProductionOrderMaterial::TYPE_INVENTORY)->get()
            ->mapWithKeys(fn (ProductionOrderMaterial $line) => [$line->id => $this->availableStock($line->material_product_id, $order->raw_material_stock_location_id)])
            ->all();
    }

    private function nextOrderNumber(int $companyId, string $date): string
    {
        $year = (int) substr($date, 0, 4);
        DB::table('document_sequences')->insertOrIgnore([
            'company_id' => $companyId, 'document_type' => DocumentSequence::PRODUCTION_ORDER,
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DocumentSequence::query()->where('company_id', $companyId)
            ->where('document_type', DocumentSequence::PRODUCTION_ORDER)->where('year', $year)
            ->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return 'PRD-'.$date.'-'.str_pad((string) $sequence->fresh()->last_number, 4, '0', STR_PAD_LEFT);
    }

    private function locked(ProductionOrder $order, User $user): ProductionOrder
    {
        abort_unless((int) $order->company_id === (int) $user->company_id, 404);

        return ProductionOrder::query()->forCurrentCompany()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function location(mixed $id, int $companyId, ?int $branchId, bool $raw, ?bool $sellable = null): StockLocation
    {
        $location = StockLocation::query()->where('company_id', $companyId)->whereKey($id)->first();
        if (! $location || ! $location->isActive() || ($branchId && (int) $location->branch_id !== (int) $branchId)
            || ($raw && ! $location->can_issue_stock) || (! $raw && ! $location->can_receive_stock)
            || ($sellable !== null && (bool) $location->is_sellable !== $sellable)
            || ($sellable === false && ! in_array($location->type, ['curing', 'quarantine'], true))) {
            throw ValidationException::withMessages([$raw ? 'raw_material_stock_location_id' : 'finished_goods_stock_location_id' => 'Select an active, branch-compatible stock location.']);
        }

        return $location;
    }

    private function lockedLocation(int $id, ProductionOrder $order, User $user, bool $raw): StockLocation
    {
        $location = StockLocation::query()->where('company_id', $order->company_id)->whereKey($id)->lockForUpdate()->first();
        $location = $location ? $this->location($location->id, $order->company_id, $order->branch_id, $raw) : null;
        if (! $location) {
            throw ValidationException::withMessages(['location' => 'Production stock location is invalid.']);
        }
        if ($user->stockLocations()->exists()) {
            $ability = $raw ? 'can_transfer' : 'can_receive';
            if (! $user->stockLocations()->where('stock_locations.id', $location->id)->wherePivot($ability, true)->exists()) {
                throw ValidationException::withMessages(['location' => 'You are not authorised for this production stock location.']);
            }
        }

        return $location;
    }

    private function availableStock(int $productId, int $locationId): string
    {
        $negative = implode(',', array_fill(0, count(StockMovement::NEGATIVE_TYPES), '?'));
        $value = StockMovement::query()->where('product_id', $productId)->where('stock_location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN quantity_in <> 0 OR quantity_out <> 0 THEN quantity_in - quantity_out WHEN movement_type IN ({$negative}) THEN -quantity ELSE quantity END), 0) available", StockMovement::NEGATIVE_TYPES)
            ->value('available');

        return bcadd((string) ($value ?? 0), '0', 4);
    }

    /** @param iterable<ProductionOrderMaterial> $lines */
    private function validateAvailableInventory(iterable $lines, int $locationId, string $quantityField): void
    {
        foreach ($lines as $line) {
            $quantity = $line->{$quantityField};
            if ($quantity === null) {
                continue;
            }

            StockMovement::query()->where('product_id', $line->material_product_id)
                ->where('stock_location_id', $locationId)->lockForUpdate()->get();
            $available = $this->availableStock($line->material_product_id, $locationId);
            if (bccomp($available, (string) $quantity, 4) < 0) {
                throw ValidationException::withMessages([
                    "materials.{$line->id}.actual_quantity" => "{$line->name} is short. Required {$quantity}; available {$available}.",
                ]);
            }
        }
    }

    private function positive(mixed $value, string $field): string
    {
        $number = $this->calculator->decimal($value);
        if (bccomp($number, '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero.']);
        }

        return bcadd($number, '0', 4);
    }

    private function nonNegative(mixed $value, string $field, int $scale = 12): string
    {
        try {
            $number = $this->calculator->decimal($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$field => 'Enter a valid non-negative quantity.']);
        }
        if (bccomp($number, '0', $scale) < 0) {
            throw ValidationException::withMessages([$field => 'Value cannot be negative.']);
        }

        return bcadd($number, '0', $scale);
    }

    private function ledgerQuantity(string $value): string
    {
        return bcadd($value, '0.00005', 4);
    }
}
