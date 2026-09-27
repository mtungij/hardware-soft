<?php

namespace App\Services;

use App\Models\InternalSaleItem;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\AuthorizationScope;
use Illuminate\Support\Collection;

class InternalSaleReportService
{
    /** @return Collection<int, array<string, mixed>> */
    public function rows(User $user, string $side, array $filters = []): Collection
    {
        abort_unless(in_array($side, ['outgoing', 'incoming'], true), 404);
        abort_unless($user->can($side === 'outgoing' ? 'reports.internal_sales' : 'reports.internal_acquisitions'), 403);

        return $this->buildRows($user, $side, $filters);
    }

    /** Location profitability has its own permission and uses the same transaction snapshots. */
    public function locationRows(User $user, string $side, array $filters = []): Collection
    {
        abort_unless(in_array($side, ['outgoing', 'incoming'], true), 404);
        abort_unless($user->can('reports.location_margins') && $user->can('stock.view_value')
            && $user->can('internal_sales.view_margin'), 403);

        return $this->buildRows($user, $side, $filters);
    }

    private function buildRows(User $user, string $side, array $filters): Collection
    {
        $visibleIds = AuthorizationScope::stockLocationIds($user);
        if ($visibleIds->isEmpty()) {
            return collect();
        }

        $status = $filters['status'] ?? 'completed';
        if (! in_array($status, ['completed', 'draft', 'cancelled', 'all'], true)) {
            $status = 'completed';
        }
        $sideColumn = $side === 'outgoing' ? 'from_location_id' : 'to_location_id';
        $reportScope = AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH);

        return InternalSaleItem::query()
            ->where('company_id', $user->company_id)
            ->with(['product.unit', 'internalSale.branch', 'internalSale.fromLocation', 'internalSale.toLocation'])
            ->whereHas('internalSale', function ($query) use ($user, $visibleIds, $filters, $status, $sideColumn, $reportScope) {
                $query->where('company_id', $user->company_id)
                    ->whereIn($sideColumn, $visibleIds)
                    ->when($reportScope !== AuthorizationScope::COMPANY, fn ($q) => $q->where('branch_id', $user->branch_id))
                    ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                    ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->whereDate('sale_date', '>=', $filters['date_from']))
                    ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->whereDate('sale_date', '<=', $filters['date_to']))
                    ->when(filled($filters['branch_id'] ?? null), fn ($q) => $q->where('branch_id', (int) $filters['branch_id']))
                    ->when(filled($filters['from_location_id'] ?? null), fn ($q) => $q->where('from_location_id', (int) $filters['from_location_id']))
                    ->when(filled($filters['to_location_id'] ?? null), fn ($q) => $q->where('to_location_id', (int) $filters['to_location_id']))
                    ->when(filled($filters['number'] ?? null), fn ($q) => $q->where('internal_sale_number', 'like', '%'.$filters['number'].'%'));
            })
            ->when(filled($filters['product_id'] ?? null), fn ($query) => $query->where('product_id', (int) $filters['product_id']))
            ->orderByDesc('id')
            ->get()
            ->map(function (InternalSaleItem $item): array {
                $sale = $item->internalSale;
                $posted = $sale?->status === 'completed';
                $baseQuantity = (float) $item->base_quantity;
                $internalValue = $posted ? (float) $item->line_total : 0.0;
                $sourceUnitCost = (float) ($item->source_acquisition_base_unit_cost ?? $item->company_base_unit_cost ?? 0);
                $sourceCost = $posted ? $baseQuantity * $sourceUnitCost : 0.0;
                $companyCost = $posted ? $baseQuantity * (float) ($item->company_base_unit_cost ?? 0) : 0.0;

                return [
                    'sale_id' => $sale?->id,
                    'number' => $sale?->internal_sale_number,
                    'date' => $sale?->sale_date,
                    'status' => $sale?->status,
                    'branch' => $sale?->branch?->name,
                    'source_id' => $sale?->from_location_id,
                    'source' => $sale?->fromLocation?->name,
                    'destination_id' => $sale?->to_location_id,
                    'destination' => $sale?->toLocation?->name,
                    'product_id' => $item->product_id,
                    'product' => $item->product?->displayNameWithSize(),
                    'sku' => $item->product?->sku,
                    'base_unit' => $item->product?->unit?->short_name,
                    'transaction_unit' => $item->transaction_unit_code_snapshot,
                    'transaction_quantity' => (float) $item->transaction_quantity,
                    'base_quantity' => $baseQuantity,
                    'internal_unit_price' => $posted ? (float) $item->internal_unit_price : null,
                    'internal_value' => $internalValue,
                    'company_cost' => $companyCost,
                    'source_cost' => $sourceCost,
                    'internal_margin' => $posted ? $internalValue - $sourceCost : 0.0,
                    'acquisition_unit_cost' => $posted ? (float) $item->destination_acquisition_base_unit_cost : null,
                    'acquisition_value' => $internalValue,
                    'posted' => $posted,
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function customerProductRows(User $user, array $filters = []): Collection
    {
        abort_unless($user->can('reports.location_margins') && $user->can('stock.view_value')
            && $user->can('internal_sales.view_margin'), 403);
        $visibleIds = AuthorizationScope::stockLocationIds($user);
        $reportScope = AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH);

        return SaleItem::query()->with(['product.unit', 'sale'])
            ->where('company_id', $user->company_id)
            ->whereIn('stock_location_id', $visibleIds)
            ->when(filled($filters['product_id'] ?? null), fn ($query) => $query->where('product_id', (int) $filters['product_id']))
            ->whereHas('sale', function ($query) use ($user, $filters, $reportScope) {
                $query->where('company_id', $user->company_id)->where('status', 'completed')
                    ->when($reportScope !== AuthorizationScope::COMPANY, fn ($q) => $q->where('branch_id', $user->branch_id))
                    ->when(filled($filters['branch_id'] ?? null), fn ($q) => $q->where('branch_id', (int) $filters['branch_id']))
                    ->when(filled($filters['date_from'] ?? null), fn ($q) => $q->whereDate('sale_date', '>=', $filters['date_from']))
                    ->when(filled($filters['date_to'] ?? null), fn ($q) => $q->whereDate('sale_date', '<=', $filters['date_to']));
            })->get()->groupBy(fn (SaleItem $item) => $item->stock_location_id.'-'.$item->product_id)
            ->map(function (Collection $items): array {
                $first = $items->first();
                $value = $items->sum(fn (SaleItem $item) => (float) $item->line_total);
                $cost = $items->sum(fn (SaleItem $item) => (float) ($item->base_quantity ?: $item->quantity)
                    * (float) ($item->location_base_unit_cost ?? $item->base_unit_cost ?? $item->unit_cost));

                return [
                    'location_id' => (int) $first->stock_location_id,
                    'product_id' => (int) $first->product_id,
                    'product' => $first->product?->displayNameWithSize(),
                    'base_unit' => $first->product?->unit?->short_name,
                    'base_quantity' => $items->sum(fn (SaleItem $item) => (float) ($item->base_quantity ?: $item->quantity)),
                    'customer_sales' => $value,
                    'acquisition_cost' => $cost,
                    'outlet_profit' => $value - $cost,
                ];
            })->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    public function totals(Collection $rows): array
    {
        $posted = $rows->where('posted', true);

        return [
            'sales' => $posted->pluck('sale_id')->unique()->count(),
            'base_quantity' => $posted->sum('base_quantity'),
            'internal_value' => $posted->sum('internal_value'),
            'source_cost' => $posted->sum('source_cost'),
            'internal_margin' => $posted->sum('internal_margin'),
            'acquisition_value' => $posted->sum('acquisition_value'),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function productSummary(Collection $rows): Collection
    {
        return $rows->where('posted', true)->groupBy('product_id')->map(function (Collection $lines): array {
            $first = $lines->first();
            $value = $lines->sum('internal_value');
            $margin = $lines->sum('internal_margin');

            return [
                'product' => $first['product'],
                'base_unit' => $first['base_unit'],
                'base_quantity' => $lines->sum('base_quantity'),
                'internal_value' => $value,
                'source_cost' => $lines->sum('source_cost'),
                'internal_margin' => $margin,
                'margin_percent' => $value > 0 ? ($margin / $value) * 100 : 0.0,
            ];
        })->sortByDesc('internal_value')->values();
    }
}
