<?php

namespace App\Services;

use Illuminate\Support\Collection;

class GoodsReceiptCostingService
{
    public function pricePreview(float $finalCost, ?float $markup, float $sellingPrice): array
    {
        $profit = $sellingPrice - $finalCost;

        return [
            'suggested_selling_price' => $markup === null ? null : round($finalCost * (1 + $markup / 100), 2),
            'profit_per_unit' => round($profit, 2),
            'profit_margin' => $sellingPrice > 0 ? round($profit / $sellingPrice * 100, 2) : 0,
        ];
    }

    // Share the same cent allocations between the live preview and receipt posting.
    public function calculate(Collection $items, array $inputs, int $additionalCents): array
    {
        $items = $items->sortBy('id')->values();
        $rows = [];
        $goodsCents = 0;
        $totalStockTicks = 0;
        foreach ($items as $item) {
            $input = $inputs[$item->id] ?? [];
            $quantity = is_numeric($input['quantity'] ?? null) ? max(0, (float) $input['quantity']) : 0;
            $stockTicks = (int) round($item->stockQuantity($quantity) * 10000);
            if ($stockTicks <= 0) {
                continue;
            }
            $lineGoodsCents = (int) round($quantity * (float) $item->cost_price * 100);
            $rows[$item->id] = ['quantity' => $quantity, 'stock_quantity' => $stockTicks / 10000,
                'stock_ticks' => $stockTicks, 'goods_cents' => $lineGoodsCents];
            $goodsCents += $lineGoodsCents;
            $totalStockTicks += $stockTicks;
        }
        $remainingCents = max(0, $additionalCents);
        $lastId = array_key_last($rows);
        foreach ($rows as $id => &$row) {
            $share = $id === $lastId ? $remainingCents
                : min($remainingCents, (int) round(max(0, $additionalCents) * $row['stock_ticks'] / $totalStockTicks));
            $remainingCents -= $share;
            $row['allocated_cents'] = $share;
            $row['landed_line_cost'] = ($row['goods_cents'] + $share) / 100;
            $row['supplier_base_unit_cost'] = round($row['goods_cents'] / 100 / $row['stock_quantity'], 6);
            $row['landed_cost_per_unit'] = round($share / 100 / $row['stock_quantity'], 6);
            $row['final_unit_cost'] = round($row['landed_line_cost'] / $row['stock_quantity'], 6);
            $item = $items->firstWhere('id', $id);
            // Product selling_price is quoted per selling unit. The legacy sale
            // factor is selling units per base unit, not base units per package.
            $row['final_selling_unit_cost'] = $row['final_unit_cost'] / ($item->product?->saleConversionFactor() ?: 1);
            $rawMarkup = $inputs[$id]['markup_percentage'] ?? null;
            $row['markup_percentage'] = is_numeric($rawMarkup) ? max(0, (float) $rawMarkup) : null;
            $sellingPrice = $inputs[$id]['preview_selling_price'] ?? $item->product?->selling_price ?? 0;
            $row += $this->pricePreview($row['final_selling_unit_cost'], $row['markup_percentage'], is_numeric($sellingPrice) ? (float) $sellingPrice : 0);
        }
        unset($row);

        return ['rows' => $rows, 'quantity' => $totalStockTicks / 10000, 'cost' => $goodsCents / 100,
            'additional' => max(0, $additionalCents) / 100, 'landed' => ($goodsCents + max(0, $additionalCents)) / 100,
            'landed_per_unit' => $totalStockTicks > 0 ? round(max(0, $additionalCents) * 100 / $totalStockTicks, 6) : 0];
    }
}
