<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InternalSale;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InternalSaleService
{
    public function sourceLocations(User $user, int $branchId)
    {
        return AuthorizationScope::stockLocationsForBranch($user, 'can_transfer', $branchId)
            ->filter(fn (StockLocation $location) => $location->can_transfer && $location->can_issue_stock)
            ->values();
    }

    public function destinationLocations(User $user, int $branchId)
    {
        return AuthorizationScope::stockLocationsForBranch($user, 'can_receive', $branchId)
            ->filter(fn (StockLocation $location) => $location->can_receive_stock)
            ->values();
    }

    public function saveDraft(array $data, User $user, ?InternalSale $existing = null): InternalSale
    {
        abort_unless($user->can('internal_sales.create'), 403);

        return DB::transaction(function () use ($data, $user, $existing) {
            $branchId = (int) ($data['branch_id'] ?? 0);
            $branch = Branch::query()->where('company_id', $user->company_id)->findOrFail($branchId);
            $this->authorizeLocations($user, $branchId, (int) ($data['from_location_id'] ?? 0), (int) ($data['to_location_id'] ?? 0));
            if (! is_array($data['items'] ?? null) || $data['items'] === []) {
                throw ValidationException::withMessages(['items' => 'Add at least one product.']);
            }

            if ($existing) {
                $sale = InternalSale::query()->where('company_id', $user->company_id)
                    ->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                if ($sale->status !== 'draft') {
                    throw ValidationException::withMessages(['internal_sale' => 'Only draft Internal Sales can be edited.']);
                }
                abort_unless(AuthorizationScope::canAccessStockLocation($user, (int) $sale->from_location_id)
                    && AuthorizationScope::canAccessStockLocation($user, (int) $sale->to_location_id), 403);
                $sale->items()->delete();
            } else {
                $sale = new InternalSale;
                $sale->company_id = $user->company_id;
                $sale->created_by = $user->id;
            }

            $number = trim((string) ($data['internal_sale_number'] ?? ''));
            if ($number === '' || mb_strlen($number) > 50) {
                throw ValidationException::withMessages(['internal_sale_number' => 'Enter an Internal Sale number (up to 50 characters).']);
            }
            if (InternalSale::query()->where('company_id', $user->company_id)
                ->where('internal_sale_number', $number)
                ->when($sale->exists, fn ($query) => $query->whereKeyNot($sale->id))->exists()) {
                throw ValidationException::withMessages(['internal_sale_number' => 'This Internal Sale number is already used.']);
            }
            $sale->fill([
                'branch_id' => $branch->id,
                'internal_sale_number' => $number,
                'sale_date' => $data['sale_date'],
                'from_location_id' => (int) $data['from_location_id'],
                'to_location_id' => (int) $data['to_location_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);
            $sale->save();

            $source = StockLocation::query()->findOrFail($sale->from_location_id);
            $seen = [];
            $totalCents = 0;
            foreach ($data['items'] as $index => $row) {
                $product = Product::query()->with('unit')->where('company_id', $user->company_id)
                    ->where('status', 'active')->findOrFail($row['product_id'] ?? 0);
                $conversion = app(ProductUnitConversionService::class)->resolveForSale(
                    $product, filled($row['product_unit_conversion_id'] ?? null) ? (int) $row['product_unit_conversion_id'] : null);
                $key = $product->id.':'.($conversion?->id ?? 0);
                if (in_array($key, $seen, true)) {
                    throw ValidationException::withMessages(["items.{$index}" => 'Duplicate product and unit row.']);
                }
                $seen[] = $key;
                $quantity = (float) ($row['quantity'] ?? 0);
                $factor = $conversion ? (float) $conversion->conversion_factor : 1.0;
                $baseQuantity = round($quantity * $factor, 4);
                if ($quantity <= 0 || ! $product->acceptsStockQuantity($baseQuantity)
                    || (! $product->allowsDecimalQuantities() && ! $product->quantityIsWhole($quantity))) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => 'Enter a valid quantity for the selected unit.']);
                }
                $resolved = app(LocationPriceService::class)->internalPriceFor($product, $source, $conversion);
                $configured = $resolved['price'];
                $entered = $row['internal_unit_price'] ?? null;
                if (! is_numeric($entered) || (float) $entered < 0 || ! preg_match('/^\d+(?:\.\d{1,2})?$/', trim((string) $entered))) {
                    throw ValidationException::withMessages(["items.{$index}.internal_unit_price" => 'Enter a valid internal unit price.']);
                }
                $unitPrice = (float) $entered;
                if (($configured === null || abs($configured - $unitPrice) > 0.005) && ! $user->can('internal_sales.override_price')) {
                    throw ValidationException::withMessages(["items.{$index}.internal_unit_price" => 'Internal price override permission is required.']);
                }
                $lineCents = (int) round($quantity * $unitPrice * 100);
                $totalCents += $lineCents;
                $sale->items()->create([
                    'company_id' => $user->company_id,
                    'product_id' => $product->id,
                    'product_unit_conversion_id' => $conversion?->id,
                    'transaction_unit_id' => $conversion?->unit_id ?? $product->unit_id,
                    'transaction_unit_name_snapshot' => $conversion?->unit?->name ?? $product->unit?->name,
                    'transaction_unit_code_snapshot' => $conversion?->unit?->short_name ?? $product->unit?->short_name,
                    'transaction_quantity' => $quantity,
                    'conversion_factor_snapshot' => $factor,
                    'base_quantity' => $baseQuantity,
                    'internal_unit_price' => $unitPrice,
                    'price_source' => $configured !== null && abs($configured - $unitPrice) <= 0.005
                        ? $resolved['source'] : 'Manual Override',
                    'line_total' => $lineCents / 100,
                    'notes' => $row['notes'] ?? null,
                ]);
            }
            $sale->update(['total_internal_value' => $totalCents / 100]);

            return $sale->refresh();
        }, 3);
    }

    public function complete(InternalSale $sale, User $user): InternalSale
    {
        abort_unless($user->can('internal_sales.complete'), 403);

        return DB::transaction(function () use ($sale, $user) {
            $sale = InternalSale::query()->with('items')->where('company_id', $user->company_id)
                ->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'completed') {
                return $sale;
            }
            if ($sale->status !== 'draft') {
                throw ValidationException::withMessages(['internal_sale' => 'Only draft Internal Sales can be completed.']);
            }
            [$from, $to] = $this->authorizeLocations($user, (int) $sale->branch_id,
                (int) $sale->from_location_id, (int) $sale->to_location_id);
            if ($sale->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Add at least one product.']);
            }
            $inventory = app(InventoryService::class);
            $requested = [];
            foreach ($sale->items as $item) {
                StockMovement::query()->where('company_id', $sale->company_id)
                    ->where('branch_id', $sale->branch_id)
                    ->where('stock_location_id', $from->id)
                    ->where('product_id', $item->product_id)->lockForUpdate()->get();
                $requested[$item->product_id] = ($requested[$item->product_id] ?? 0) + (float) $item->base_quantity;
                if ($requested[$item->product_id] > $inventory->getProductStock($item->product_id, $from->id, $sale->branch_id)) {
                    throw ValidationException::withMessages(['items' => 'Source stock is insufficient for '.$item->product?->name.'.']);
                }
            }

            foreach ($sale->items as $item) {
                $baseQuantity = (float) $item->base_quantity;
                $companyCost = $inventory->getAverageCost($item->product_id, $from->id, $sale->branch_id);
                $sourceAcquisition = $inventory->getLocationAcquisitionCost($item->product_id, $from->id, $sale->branch_id);
                $destinationAcquisition = round((float) $item->line_total / $baseQuantity, 6);
                $item->update([
                    'company_base_unit_cost' => $companyCost,
                    'source_acquisition_base_unit_cost' => $sourceAcquisition,
                    'destination_acquisition_base_unit_cost' => $destinationAcquisition,
                ]);
                foreach ([[$from, 'internal_sale_out', $sourceAcquisition], [$to, 'internal_sale_in', $destinationAcquisition]] as [$location, $type, $acquisitionCost]) {
                    StockMovement::create([
                        'company_id' => $sale->company_id,
                        'branch_id' => $sale->branch_id,
                        'product_id' => $item->product_id,
                        'stock_location_id' => $location->id,
                        'source_location_id' => $from->id,
                        'destination_location_id' => $to->id,
                        'movement_type' => $type,
                        'quantity' => $baseQuantity,
                        'quantity_in' => $type === 'internal_sale_in' ? $baseQuantity : 0,
                        'quantity_out' => $type === 'internal_sale_out' ? $baseQuantity : 0,
                        'transaction_unit_id' => $item->transaction_unit_id,
                        'transaction_unit_name_snapshot' => $item->transaction_unit_name_snapshot,
                        'transaction_unit_code_snapshot' => $item->transaction_unit_code_snapshot,
                        'product_unit_conversion_id' => $item->product_unit_conversion_id,
                        'transaction_quantity' => $item->transaction_quantity,
                        'conversion_factor_snapshot' => $item->conversion_factor_snapshot,
                        'unit_cost' => $companyCost,
                        'location_acquisition_unit_cost' => $acquisitionCost,
                        'unit_price' => $item->internal_unit_price,
                        'transaction_unit_price' => $item->internal_unit_price,
                        'reference_type' => InternalSale::class,
                        'reference_id' => $sale->id,
                        'idempotency_key' => (string) Str::uuid(),
                        'notes' => "Internal Sale {$sale->internal_sale_number} / item {$item->id}",
                        'created_by' => $user->id,
                        'movement_date' => $sale->sale_date,
                    ]);
                }
            }
            $sale->update(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()]);

            return $sale->refresh();
        }, 3);
    }

    public function cancelDraft(InternalSale $sale, User $user): InternalSale
    {
        abort_unless($user->can('internal_sales.cancel'), 403);

        return DB::transaction(function () use ($sale, $user) {
            $sale = InternalSale::query()->where('company_id', $user->company_id)
                ->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'cancelled') {
                return $sale;
            }
            if ($sale->status !== 'draft') {
                throw ValidationException::withMessages(['internal_sale' => 'Completed Internal Sales are immutable.']);
            }
            abort_unless(AuthorizationScope::canAccessStockLocation($user, (int) $sale->from_location_id)
                && AuthorizationScope::canAccessStockLocation($user, (int) $sale->to_location_id), 403);
            $sale->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now()]);

            return $sale->refresh();
        });
    }

    /** @return array{0: StockLocation, 1: StockLocation} */
    private function authorizeLocations(User $user, int $branchId, int $fromId, int $toId): array
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_location_id' => 'Source and destination must differ.']);
        }
        if (! Branch::query()->where('company_id', $user->company_id)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Branch does not belong to this company.']);
        }
        $from = $this->sourceLocations($user, $branchId)->firstWhere('id', $fromId);
        $to = $this->destinationLocations($user, $branchId)->firstWhere('id', $toId);
        if (! $from) {
            throw ValidationException::withMessages(['from_location_id' => 'You cannot issue stock from this location.']);
        }
        if (! $to) {
            throw ValidationException::withMessages(['to_location_id' => 'You cannot receive stock at this location.']);
        }
        if ((int) $from->company_id !== (int) $user->company_id || (int) $to->company_id !== (int) $user->company_id) {
            throw ValidationException::withMessages(['location' => 'Locations must belong to this company.']);
        }

        return [$from, $to];
    }
}
