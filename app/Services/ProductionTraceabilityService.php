<?php

namespace App\Services;

use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Support\CompanyFeatures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ProductionTraceabilityService
{
    public function find(string $search): ?ProductionCuringBatch
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }

        $user = auth()->user();

        return ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo($user)
            ->where(function (Builder $q) use ($search): void {
                $q->where('batch_number', 'like', '%'.$search.'%')
                    ->orWhereHas('productionOrder', fn (Builder $o) => $o->where('order_number', 'like', '%'.$search.'%'))
                    ->orWhereHas('qualityInspections', fn (Builder $i) => $i->where('inspection_number', 'like', '%'.$search.'%'))
                    ->orWhereHas('releases', fn (Builder $r) => $r->where('release_number', 'like', '%'.$search.'%')->orWhere('posting_reference', 'like', '%'.$search.'%'))
                    ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', '%'.$search.'%'));
            })->with([
                'product.productFamily', 'product.unit', 'branch', 'machine', 'sourceLocation', 'defaultReleaseLocation',
                'productionOrder.snapshot.outputUnit', 'productionOrder.assignment.mould.family', 'productionOrder.assignment.mouldInstallation',
                'productionOrder.materials.materialProduct', 'productionOrder.materials.unit', 'productionOrder.costing',
                'actions.creator', 'qualityInspections.inspector', 'qualityInspections.approver', 'qualityInspections.results',
                'qualityHolds', 'releases.sourceLocation', 'releases.destinationLocation', 'releases.releaser',
            ])->latest('production_date')->first();
    }

    public function stockMovements(ProductionCuringBatch $batch): Collection
    {
        return StockMovement::query()->where('company_id', CompanyFeatures::companyId())
            ->where(fn (Builder $q) => $q->where('production_curing_batch_id', $batch->id)->orWhere(fn (Builder $x) => $x->where('reference_type', ProductionOrder::class)->where('reference_id', $batch->production_order_id)))
            ->with(['product', 'stockLocation', 'sourceLocation', 'destinationLocation'])->oldest('movement_date')->oldest('id')->get();
    }

    public function potentialSales(ProductionCuringBatch $batch): Collection
    {
        $destinations = $batch->releases->pluck('destination_stock_location_id')->filter()->unique();
        if ($destinations->isEmpty()) {
            return collect();
        }

        return SaleItem::query()->where('company_id', CompanyFeatures::companyId())->where('product_id', $batch->product_id)->whereIn('stock_location_id', $destinations)
            ->whereHas('sale', fn (Builder $q) => $q->where('sale_date', '>=', $batch->releases->min('released_at')))
            ->with(['sale', 'stockLocation'])->latest('id')->limit(50)->get();
    }
}
