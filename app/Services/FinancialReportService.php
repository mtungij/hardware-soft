<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLocation;

class FinancialReportService
{
    public function profitLoss(?int $branchId, string $from, string $to): array
    {
        $salesQuery = Sale::query()
            ->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        $revenue = (float) (clone $salesQuery)->sum('total_amount');
        $saleIds = (clone $salesQuery)->pluck('id');
        $cogs = (float) SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get()
            ->sum(fn (SaleItem $item) => (float) $item->quantity * (float) $item->unit_cost);
        $expenses = (float) Expense::query()
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

    public function stockValuation(?int $branchId = null): array
    {
        $inventory = app(InventoryService::class);
        $rows = [];

        foreach (StockLocation::query()->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->with('branch')->get() as $location) {
            foreach (Product::query()->with('category')->get() as $product) {
                $quantity = $inventory->getProductStock($product->id, $location->id, $location->branch_id);
                if ($quantity <= 0) {
                    continue;
                }

                $averageCost = $inventory->getAverageCost($product->id, $location->id, $location->branch_id);
                $rows[] = [
                    'branch' => $location->branch?->name,
                    'location' => $location->name,
                    'product' => $product->name,
                    'category' => $product->category?->name,
                    'quantity' => $quantity,
                    'average_cost' => $averageCost,
                    'value' => $quantity * $averageCost,
                ];
            }
        }

        return $rows;
    }

    public function purchases(?int $branchId, string $from, string $to)
    {
        return Purchase::query()
            ->with(['branch', 'supplier'])
            ->whereBetween('purchase_date', [$from, $to])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest()
            ->get();
    }
}
