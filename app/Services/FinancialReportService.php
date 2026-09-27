<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\InternalSale;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class FinancialReportService
{
    public function profitLoss(?int $branchId, string $from, string $to): array
    {
        $user = Auth::user();
        $salesQuery = Sale::query()
            ->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        if ($user instanceof User) {
            AuthorizationScope::reports($salesQuery, $user);
        }

        $revenue = (float) (clone $salesQuery)->sum('total_amount');
        $saleIds = (clone $salesQuery)->pluck('id');
        $cogs = (float) SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get()
            ->sum(fn (SaleItem $item) => $item->base_unit_cost !== null
                ? (float) $item->base_quantity * (float) $item->base_unit_cost
                : (float) $item->quantity * (float) $item->unit_cost);
        $expenses = (float) Expense::query()
            ->when($user instanceof User, function ($query) use ($user) {
                $query->where('company_id', $user->company_id);

                return match (AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH)) {
                    AuthorizationScope::COMPANY => $query,
                    AuthorizationScope::BRANCH => $query->where('branch_id', $user->branch_id),
                    default => $query->whereRaw('1 = 0'),
                };
            })
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->sum('amount');

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $revenue - $cogs,
            'expenses' => $expenses,
            'net_profit' => $revenue - $cogs - $expenses,
        ];
    }

    public function locationMargins(?int $branchId, string $from, string $to): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }
        $locations = $this->valuationLocations($branchId);
        $inventory = app(InventoryService::class);
        $valuationRows = collect($this->stockValuation($branchId))->groupBy('stock_location_id');
        $sales = SaleItem::query()->with('sale')
            ->where('company_id', $user->company_id)
            ->whereIn('stock_location_id', $locations->pluck('id'))
            ->whereHas('sale', fn ($query) => $query->where('status', 'completed')
                ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to))
            ->get()->groupBy('stock_location_id');
        $internal = InternalSale::query()->with('items')
            ->where('company_id', $user->company_id)
            ->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->where(fn ($query) => $query->whereIn('from_location_id', $locations->pluck('id'))
                ->orWhereIn('to_location_id', $locations->pluck('id')))
            ->get();

        return $locations->map(function (StockLocation $location) use ($sales, $internal, $valuationRows, $inventory, $branchId): array {
            $customerLines = $sales->get($location->id, collect());
            $out = $internal->where('from_location_id', $location->id);
            $in = $internal->where('to_location_id', $location->id);
            $externalRevenue = $customerLines->sum(fn (SaleItem $line) => (float) $line->line_total);
            $locationCogs = $customerLines->sum(fn (SaleItem $line) => (float) ($line->base_quantity ?: $line->quantity)
                * (float) ($line->location_base_unit_cost ?? $line->base_unit_cost ?? $line->unit_cost));
            $companyCogs = $customerLines->sum(fn (SaleItem $line) => (float) ($line->base_quantity ?: $line->quantity)
                * (float) ($line->base_unit_cost ?? $line->unit_cost));
            $internalOut = $out->sum('total_internal_value');
            $sourceCost = $out->sum(fn (InternalSale $sale) => $sale->items->sum(fn ($item) => (float) $item->base_quantity * (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost)));
            $internalIn = $in->sum('total_internal_value');
            $commercialValue = $valuationRows->get($location->id, collect())->sum(function (array $row) use ($inventory, $location, $branchId): float {
                $product = Product::query()->where('company_id', $location->company_id)
                    ->find($row['product_id']);
                if (! $product) {
                    return 0;
                }

                return (float) $row['quantity'] * $inventory->getLocationAcquisitionCost($product->id, $location->id, $branchId ?? $location->branch_id);
            });

            return [
                'location_id' => $location->id,
                'location' => $location->name,
                'branch' => $location->branch?->name,
                'external_revenue' => $externalRevenue,
                'location_cogs' => $locationCogs,
                'outlet_margin' => $externalRevenue - $locationCogs,
                'internal_sales_out' => $internalOut,
                'internal_purchases_in' => $internalIn,
                'source_internal_margin' => $internalOut - $sourceCost,
                'location_gross_margin' => $externalRevenue - $locationCogs + $internalOut - $sourceCost,
                'company_cogs' => $companyCogs,
                'company_stock_value' => $valuationRows->get($location->id, collect())->sum('value'),
                'location_commercial_value' => $commercialValue,
            ];
        })->all();
    }

    /** Active locations visible under the existing stock scope, regardless of classification. */
    public function valuationLocations(?int $branchId = null): Collection
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return collect();
        }

        if ($branchId === null && AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY) {
            $branchId = $user->branch_id;
        }

        if ($branchId !== null && ! Branch::query()->where('company_id', $user->company_id)->whereKey($branchId)->exists()) {
            return collect();
        }

        if ($branchId !== null && AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY && $branchId !== (int) $user->branch_id) {
            return collect();
        }

        $ids = $branchId !== null
            ? AuthorizationScope::stockLocationsForBranch($user, 'can_view', $branchId)->pluck('id')
            : AuthorizationScope::stockLocationIds($user);

        return StockLocation::query()->where('company_id', $user->company_id)->whereIn('id', $ids)->with('branch')->orderBy('name')->get();
    }

    public function valuationBranches(): Collection
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return collect();
        }

        return Branch::query()->where('company_id', $user->company_id)
            ->when(AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY,
                fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')->get();
    }

    public function stockValuation(?int $branchId = null, ?int $stockLocationId = null, string $search = ''): array
    {
        $inventory = app(InventoryService::class);
        $rows = [];
        $locations = $this->valuationLocations($branchId)
            ->filter(fn (StockLocation $location) => $stockLocationId === null || $location->id === $stockLocationId);
        if ($locations->isEmpty()) {
            return [];
        }
        $products = Product::query()->whereIn('company_id', $locations->pluck('company_id')->unique())
            ->with(['category', 'measurementType', 'size', 'unit'])->get();

        foreach ($locations as $location) {
            // Shared locations are restricted to the selected branch's ledger when filtered.
            $ledgerBranchId = $branchId ?? $location->branch_id;
            if ($ledgerBranchId === null && AuthorizationScope::scopeFor(Auth::user(), 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY) {
                $ledgerBranchId = Auth::user()->branch_id;
            }
            $quantities = $inventory->getProductStocks($products->modelKeys(), $location->id, $ledgerBranchId);
            foreach ($products as $product) {
                $quantity = $quantities[$product->id] ?? 0;
                if ($quantity <= 0) {
                    continue;
                }

                $averageCost = $inventory->getAverageCost($product->id, $location->id, $ledgerBranchId);
                $rows[] = [
                    'branch_id' => $location->branch_id,
                    'product_id' => $product->id,
                    'stock_location_id' => $location->id,
                    'branch' => $location->branch?->name,
                    'location' => $location->name,
                    'product' => $product->displayName(),
                    'measurement_type' => $product->measurementType?->name ?? str($product->measurementCode())->title()->toString(),
                    'size' => $product->sizeLabel(),
                    'unit' => $product->unit?->short_name,
                    'category' => $product->category?->name,
                    'quantity' => $quantity,
                    'average_cost' => $averageCost,
                    'value' => $quantity * $averageCost,
                ];
            }
        }

        return collect($rows)->filter(fn ($row) => blank($search) || str_contains(mb_strtolower($row['product'].' '.$row['size'].' '.$row['category']), mb_strtolower($search)))->values()->all();
    }

    public function stockValueByLocation(?int $branchId = null): Collection
    {
        return collect($this->stockValuation($branchId))->groupBy('stock_location_id')
            ->map(fn (Collection $rows) => [
                'stock_location_id' => $rows->first()['stock_location_id'],
                'branch_id' => $rows->first()['branch_id'],
                'branch' => $rows->first()['branch'],
                'location' => $rows->first()['location'],
                'quantity' => $rows->sum('quantity'),
                'value' => $rows->sum('value'),
            ])->values();
    }

    public function stockMovementsForLocations(?int $branchId = null): Builder
    {
        $user = Auth::user();
        if ($branchId === null && $user instanceof User && AuthorizationScope::scopeFor($user, 'stock_scope', AuthorizationScope::ASSIGNED_LOCATIONS) !== AuthorizationScope::COMPANY) {
            $branchId = $user->branch_id;
        }

        return StockMovement::query()
            ->whereIn('stock_location_id', $this->valuationLocations($branchId)->pluck('id'))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId));
    }

    public function stockReceivedToday(?int $branchId = null): float
    {
        return (float) $this->stockMovementsForLocations($branchId)
            ->whereIn('movement_type', ['purchase_in', 'purchase_receipt'])
            ->whereDate('movement_date', today())
            ->get()->sum(fn (StockMovement $movement) => $movement->signedQuantity());
    }

    public function purchases(?int $branchId, string $from, string $to)
    {
        $user = Auth::user();

        return Purchase::query()
            ->when($user instanceof User, function ($query) use ($user) {
                $query->where('company_id', $user->company_id);

                return match (AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH)) {
                    AuthorizationScope::COMPANY => $query,
                    AuthorizationScope::OWN => $query->where('created_by', $user->id),
                    default => $query->where('branch_id', $user->branch_id),
                };
            })
            ->with(['branch', 'supplier', 'items.purchaseUnit', 'items.stockUnit', 'items.product'])
            ->whereBetween('purchase_date', [$from, $to])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->get();
    }
}
