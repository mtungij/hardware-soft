<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionCuringRelease;
use App\Models\ProductionMould;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionQualityInspection;
use App\Support\CompanyFeatures;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProductionReportService
{
    public const REPORTS = [
        'summary' => ['title' => 'Production Summary', 'description' => 'Production output by order, product, machine, mould and immutable recipe snapshot.'],
        'material-consumption' => ['title' => 'Material Consumption', 'description' => 'Planned and actual material usage and variance from order snapshots.'],
        'costing' => ['title' => 'Production Cost', 'description' => 'Historical production costs and loss allocation from costing snapshots.', 'cost' => true],
        'yield-loss' => ['title' => 'Yield & Loss', 'description' => 'Production yield, rejection, curing damage and released output.'],
        'curing' => ['title' => 'Curing', 'description' => 'Curing inventory, ageing, quality approval and release readiness.'],
        'quality' => ['title' => 'Quality Control', 'description' => 'Inspection outcomes, approvals, critical failures, retests and holds.'],
        'releases' => ['title' => 'Finished Goods Releases', 'description' => 'Immutable release events and their paired inventory-ledger postings.'],
        'machine-performance' => ['title' => 'Machine Performance', 'description' => 'Output versus scheduled capacity, acceptance and mould changes.'],
        'mould-performance' => ['title' => 'Mould Performance', 'description' => 'Historical mould assignments, output, installations and maintenance state.'],
    ];

    public function definition(string $report): array
    {
        return self::REPORTS[$report] ?? abort(404);
    }

    public function dashboard(array $filters): array
    {
        $orders = $this->orderQuery($filters)->with(['product.unit', 'curingBatch', 'costing'])->get();
        $completed = $orders->where('status', ProductionOrder::STATUS_COMPLETED);
        $costings = $completed->pluck('costing')->filter();
        $canViewCost = auth()->user()?->can('production.view_cost_reports') ?? false;
        $costComplete = $completed->isNotEmpty() && $costings->count() === $completed->count()
            && ! $costings->contains(fn ($costing) => $costing->has_missing_cost);

        return [
            ['label' => 'Production Orders', 'value' => (string) $orders->count()],
            ['label' => 'Planned Quantity', 'value' => $this->quantityByUnit($orders, 'planned_quantity')],
            ['label' => 'Total Produced', 'value' => $this->quantityByUnit($orders, 'total_produced_quantity')],
            ['label' => 'Accepted Output', 'value' => $this->quantityByUnit($orders, 'accepted_quantity')],
            ['label' => 'Rejected Output', 'value' => $this->quantityByUnit($orders, 'rejected_quantity')],
            ['label' => 'Curing Damage', 'value' => $this->quantityByUnit($completed, 'curingBatch.damaged_quantity')],
            ['label' => 'Released Finished Goods', 'value' => $this->quantityByUnit($completed, 'curingBatch.released_quantity')],
            ['label' => 'Production Cost', 'value' => ! $canViewCost ? 'Restricted' : ($costComplete ? 'TZS '.$this->decimalSum($costings, 'total_actual_cost', 2) : 'Incomplete costing data')],
            ['label' => 'Average Cost / Accepted Unit', 'value' => ! $canViewCost ? 'Restricted' : ($costComplete ? 'TZS '.$this->weightedCost($costings, 'total_actual_cost', 'accepted_quantity') : 'Cost unavailable')],
            ['label' => 'Yield Percentage', 'value' => $this->percentage($this->decimalSumRaw($orders, 'accepted_quantity'), $this->decimalSumRaw($orders, 'total_produced_quantity'))],
            ['label' => 'Machines Used', 'value' => (string) $orders->pluck('machine_id')->filter()->unique()->count()],
            ['label' => 'Products Produced', 'value' => (string) $orders->pluck('product_id')->filter()->unique()->count()],
        ];
    }

    public function dailyAcceptedChart(array $filters): array
    {
        $orders = $this->orderQuery($filters)->with('product.unit')->orderBy('production_date')->get(['production_date', 'product_id', 'accepted_quantity']);
        $labels = $orders->pluck('production_date')->filter()->map->toDateString()->unique()->values();
        $datasets = $orders->groupBy(fn ($order) => $order->product?->unit?->short_name ?: 'unit')->map(function (Collection $rows, string $unit) use ($labels): array {
            $byDate = $rows->groupBy(fn ($order) => $order->production_date->toDateString());

            return ['label' => 'Accepted ('.$unit.')', 'data' => $labels->map(fn (string $date) => $this->decimalSumRaw($byDate->get($date, collect()), 'accepted_quantity'))->all()];
        })->values()->all();

        return ['labels' => $labels->all(), 'datasets' => $datasets];
    }

    public function report(string $report, array $filters, int $perPage = 20, bool $export = false): array
    {
        $this->definition($report);
        $limit = $export ? 5000 : $perPage;

        return match ($report) {
            'summary' => $this->summary($filters, $limit, $export),
            'material-consumption' => $this->materials($filters, $limit, $export),
            'costing' => $this->costing($filters, $limit, $export),
            'yield-loss' => $this->yieldLoss($filters, $limit, $export),
            'curing' => $this->curing($filters, $limit, $export),
            'quality' => $this->quality($filters, $limit, $export),
            'releases' => $this->releases($filters, $limit, $export),
            'machine-performance' => $this->machines($filters, $limit, $export),
            'mould-performance' => $this->moulds($filters, $limit, $export),
        };
    }

    public function filterOptions(): array
    {
        $companyId = CompanyFeatures::companyId();
        $branchScope = fn (Builder $query) => $query->where('company_id', $companyId)->orderBy('name')->get(['id', 'name']);

        return [
            'branches' => $branchScope(Branch::query()),
            'products' => $branchScope(Product::query()->manufactured()),
            'families' => $branchScope(ProductFamily::query()),
            'machines' => Machine::query()->forCurrentCompany()->orderBy('name')->get(['id', 'name']),
            'moulds' => ProductionMould::query()->forCurrentCompany()->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function summary(array $filters, int $limit, bool $export): array
    {
        $group = $filters['group_by'] ?? '';
        $query = $this->orderQuery($filters)->with(['branch', 'product.productFamily', 'product.unit', 'machine', 'assignment.mould', 'snapshot', 'starter', 'completer']);
        $query = (match ($group) {
            'day' => $query->orderBy('production_orders.production_date'), 'product' => $query->orderBy('production_orders.product_id'), 'family' => $query->orderBy(Product::query()->select('product_family_id')->whereColumn('products.id', 'production_orders.product_id')), 'machine' => $query->orderBy('production_orders.machine_id'), 'branch' => $query->orderBy('production_orders.branch_id'), default => $query->latest('production_orders.production_date')
        })->latest('production_orders.id');
        $headers = ['Date', 'Order', 'Site', 'Product / Family', 'Machine / Assigned Mould', 'Recipe Snapshot', 'Planned', 'Produced', 'Accepted', 'Rejected', 'Status', 'Started / Completed', 'Operators'];
        if ($group !== '') {
            $headers = ['Group', ...$headers];
        }

        $result = $this->result($query, $limit, $export,
            $headers,
            function ($o) use ($group) {
                $row = [$o->production_date?->format('d M Y'), $o->order_number, $o->branch?->name ?: 'Company-wide', ($o->product?->name ?: '—').' / '.($o->product?->productFamily?->name ?: '—'), ($o->machine?->name ?: '—').' / '.($o->assignment?->mould?->name ?: 'Not recorded'), trim(($o->snapshot?->recipe_name ?: 'Not captured').' '.($o->snapshot?->recipe_version ? 'v'.$o->snapshot->recipe_version : '')), $this->q($o->planned_quantity, $o->product?->unit?->short_name), $this->q($o->total_produced_quantity, $o->product?->unit?->short_name), $this->q($o->accepted_quantity, $o->product?->unit?->short_name), $this->q($o->rejected_quantity, $o->product?->unit?->short_name), str($o->status)->headline(), $this->dateTime($o->started_at).' / '.$this->dateTime($o->completed_at), ($o->starter?->name ?: '—').' / '.($o->completer?->name ?: '—')];

                return $group === '' ? $row : [$this->summaryGroup($o, $group), ...$row];
            });
        $result['totals'] = $this->orderTotalsByUnit($filters);

        return $result;
    }

    private function materials(array $filters, int $limit, bool $export): array
    {
        $canViewCost = auth()->user()?->can('production.view_cost_reports') ?? false;
        $group = $filters['group_by'] ?? '';
        $query = ProductionOrderMaterial::query()->where('company_id', CompanyFeatures::companyId())
            ->whereHas('order', fn (Builder $q) => $this->applyOrderFilters($q, $filters))
            ->with(['order.product', 'order.rawMaterialLocation', 'materialProduct', 'unit']);
        $query = match ($group) {
            'material' => $query->orderBy('material_product_id'), 'product' => $query->orderBy(ProductionOrder::query()->select('product_id')->whereColumn('production_orders.id', 'production_order_materials.production_order_id')), 'order' => $query->orderBy('production_order_id'), 'day' => $query->orderBy(ProductionOrder::query()->select('production_date')->whereColumn('production_orders.id', 'production_order_materials.production_order_id')), default => $query->latest('id')
        };

        $headers = ['Order / Date', 'Finished Product', 'Raw Material', 'Unit', 'Planned Qty', 'Actual Qty', 'Qty Variance', 'Variance %'];
        if ($canViewCost) {
            $headers = [...$headers, 'Planned Unit Cost', 'Actual Unit Cost', 'Planned Cost', 'Actual Cost', 'Cost Variance'];
        }
        $headers[] = 'Raw Material Location';
        if ($group !== '') {
            $headers = ['Group', ...$headers];
        }

        $result = $this->result($query, $limit, $export, $headers,
            function ($m) use ($canViewCost, $group) {
                $quantityVariance = bcsub((string) ($m->actual_quantity ?? 0), (string) ($m->planned_quantity ?? 0), 4);
                $actualUnitCost = bccomp((string) ($m->actual_quantity ?? 0), '0', 12) === 1 ? bcdiv((string) $m->actual_cost, (string) $m->actual_quantity, 4) : null;
                $row = [($m->order?->order_number ?: '—').' / '.$m->order?->production_date?->format('d M Y'), $m->order?->product?->name ?: '—', $m->materialProduct?->name ?: $m->name, $m->unit?->short_name ?: '—', $this->q($m->planned_quantity), $this->q($m->actual_quantity), $this->q($quantityVariance), $this->percentage($quantityVariance, $m->planned_quantity)];
                if ($canViewCost) {
                    $row = [...$row, $m->unit_cost === null ? 'Cost unavailable' : $this->money($m->unit_cost), $actualUnitCost === null ? 'Cost unavailable' : $this->money($actualUnitCost), $this->money($m->planned_cost), $this->money($m->actual_cost), $this->money(bcsub((string) $m->actual_cost, (string) $m->planned_cost, 4))];
                }
                $row = [...$row, $m->order?->rawMaterialLocation?->name ?: '—'];

                return $group === '' ? $row : [$this->materialGroup($m, $group), ...$row];
            });
        $result['totals'] = $this->materialTotalsByUnit($filters, $canViewCost);

        return $result;
    }

    private function costing(array $filters, int $limit, bool $export): array
    {
        $query = ProductionOrderCosting::query()->forCurrentCompany()->accessibleTo(auth()->user())
            ->whereHas('productionOrder', fn (Builder $q) => $this->applyOrderFilters($q, $filters))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->with(['productionOrder.product', 'productionOrder.machine', 'finalizer'])->latest('id');

        $result = $this->result($query, $limit, $export,
            ['Costing', 'Order / Product', 'Date', 'Status', 'Planned Inventory', 'Actual Inventory', 'Planned Non-Inventory', 'Actual Non-Inventory', 'Total Planned', 'Total Actual', 'Rejected Loss', 'Curing Damage Loss', 'Accepted Unit Cost', 'Sellable Unit Cost', 'Variance', 'Variance %', 'Missing Cost', 'Finalised'],
            fn ($c) => [$c->costing_number, ($c->productionOrder?->order_number ?: '—').' / '.($c->productionOrder?->product?->name ?: '—'), $c->productionOrder?->production_date?->format('d M Y'), str($c->status)->headline(), $this->money($c->planned_inventory_material_cost), $this->money($c->actual_inventory_material_cost), $this->money($c->planned_non_inventory_cost), $this->money($c->actual_non_inventory_cost), $this->money($c->total_planned_cost), $this->money($c->total_actual_cost), $this->money($c->rejected_loss_cost), $this->money($c->curing_damage_loss_cost), $this->money($c->cost_per_accepted_unit, 4), $this->money($c->cost_per_released_unit, 4), $this->money($c->cost_variance), $this->q($c->variance_percentage).'%', $c->has_missing_cost ? 'Yes' : 'No', $this->dateTime($c->finalized_at).' / '.($c->finalizer?->name ?: '—')]);
        $totals = (clone $query)->reorder()->selectRaw('SUM(total_actual_cost) AS actual_cost, SUM(rejected_loss_cost) AS rejected_loss, SUM(curing_damage_loss_cost) AS damage_loss, SUM(accepted_quantity) AS accepted_qty, SUM(released_quantity) AS released_qty')->first();
        $result['totals'] = [
            'Total actual production cost' => $this->money($totals?->actual_cost),
            'Total rejected loss' => $this->money($totals?->rejected_loss),
            'Total curing damage loss' => $this->money($totals?->damage_loss),
            'Weighted accepted-unit cost' => $this->weightedValue($totals?->actual_cost, $totals?->accepted_qty),
            'Weighted sellable-unit cost' => $this->weightedValue($totals?->actual_cost, $totals?->released_qty),
        ];

        return $result;
    }

    private function yieldLoss(array $filters, int $limit, bool $export): array
    {
        $query = $this->orderQuery($filters)->with(['product.unit', 'curingBatch', 'qualityInspections'])->latest('production_date')->latest('id');

        return $this->result($query, $limit, $export,
            ['Order / Product', 'Planned Quantity', 'Produced', 'Accepted Into Curing', 'Production Reject', 'QC Reject', 'Curing Damage', 'Released Quantity', 'Remaining Curing', 'Yield %', 'QC Pass Rate', 'QC Failure Rate', 'Rework Quantity', 'Quarantined Quantity', 'Total Recorded Loss'],
            function ($o) {
                $u = $o->product?->unit?->short_name;
                $damage = $o->curingBatch?->damaged_quantity ?? 0;
                $qcRejected = $o->curingBatch?->qc_rejected_quantity ?? 0;
                $approved = $o->qualityInspections->where('approval_status', 'approved');
                $qcInspected = $approved->reduce(fn (string $sum, $i) => bcadd($sum, (string) ($i->inspected_quantity ?? 0), 12), '0');
                $qcPassed = $approved->reduce(fn (string $sum, $i) => bcadd($sum, (string) ($i->passed_quantity ?? 0), 12), '0');
                $rework = $approved->where('disposition', 'rework')->reduce(fn (string $sum, $i) => bcadd($sum, (string) ($i->failed_quantity ?? 0), 12), '0');
                $quarantined = $approved->where('disposition', 'quarantine')->reduce(fn (string $sum, $i) => bcadd($sum, (string) ($i->failed_quantity ?? 0), 12), '0');
                $loss = bcadd(bcadd((string) $o->rejected_quantity, (string) $damage, 12), (string) $qcRejected, 12);

                return [($o->order_number ?: '—').' / '.($o->product?->name ?: '—'), $this->q($o->planned_quantity, $u), $this->q($o->total_produced_quantity, $u), $this->q($o->accepted_quantity, $u), $this->q($o->rejected_quantity, $u), $this->q($qcRejected, $u), $this->q($damage, $u), $this->q($o->curingBatch?->released_quantity, $u), $this->q($o->curingBatch?->remaining_quantity, $u), $this->percentage($o->accepted_quantity, $o->total_produced_quantity), $this->percentage($qcPassed, $qcInspected), $this->percentage($qcRejected, $qcInspected), $this->q($rework, $u), $this->q($quarantined, $u), $this->q($loss, $u)];
            });
    }

    private function curing(array $filters, int $limit, bool $export): array
    {
        $query = ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo(auth()->user())
            ->with(['productionOrder', 'product.unit', 'branch', 'machine', 'sourceLocation', 'defaultReleaseLocation', 'qualityInspections', 'qualityHolds'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('production_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('production_date', '<=', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filters['product_id'] ?? null, fn (Builder $q, $v) => $q->where('product_id', $v))
            ->when($filters['family_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('product', fn (Builder $p) => $p->where('product_family_id', $v)))
            ->when($filters['machine_id'] ?? null, fn (Builder $q, $v) => $q->where('machine_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('batch_number', 'like', '%'.$v.'%'))->latest('production_date')->latest('id');

        return $this->result($query, $limit, $export,
            ['Batch / Order', 'Product', 'Site / Machine', 'Curing / Destination', 'Accepted Into Curing', 'Production Reject', 'QC Reject', 'Curing Damage', 'Released', 'Remaining', 'Release Eligible', 'Curing Start', 'Earliest Sellable', 'Full Curing', 'Age', 'Ageing Bucket', 'Progress', 'QC Approval', 'Quarantine', 'Active Hold', 'Status'],
            function ($b) {
                $u = $b->product?->unit?->short_name;
                $elapsed = max(0, $b->curing_started_at?->diffInDays(now()) ?? 0);
                $total = max(1, $b->curing_started_at?->diffInDays($b->full_curing_at) ?? 1);
                $progress = min(100, (int) floor(($elapsed / $total) * 100));
                $ageBucket = bccomp((string) $b->remaining_quantity, '0', 12) === 1 && now()->gt($b->full_curing_at) ? 'Past full curing, unreleased' : (bccomp((string) $b->remaining_quantity, '0', 12) === 1 && now()->gt($b->minimum_sellable_at) ? 'Past earliest release, unreleased' : ($elapsed < 3 ? 'Less than 3 days' : ($elapsed <= 7 ? '3–7 days' : ($elapsed <= 14 ? '8–14 days' : 'More than 14 days'))));

                return [($b->batch_number ?: '—').' / '.($b->productionOrder?->order_number ?: '—'), $b->product?->name ?: '—', ($b->branch?->name ?: 'Company-wide').' / '.($b->machine?->name ?: '—'), ($b->sourceLocation?->name ?: '—').' / '.($b->defaultReleaseLocation?->name ?: '—'), $this->q($b->accepted_quantity, $u), $this->q($b->productionOrder?->rejected_quantity, $u), $this->q($b->qc_rejected_quantity, $u), $this->q($b->damaged_quantity, $u), $this->q($b->released_quantity, $u), $this->q($b->remaining_quantity, $u), $this->q($b->release_eligible_quantity, $u), $this->dateTime($b->curing_started_at), $this->dateTime($b->minimum_sellable_at), $this->dateTime($b->full_curing_at), $elapsed.' days', $ageBucket, $progress.'%', $b->qc_approved_at ? 'Approved' : 'Pending', $b->status === ProductionCuringBatch::STATUS_QUARANTINED ? 'Yes' : 'No', $b->qualityHolds->where('status', 'active')->isNotEmpty() ? 'Yes' : 'No', str($b->resolvedStatus())->headline()];
            });
    }

    private function quality(array $filters, int $limit, bool $export): array
    {
        $query = ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo(auth()->user())->with(['curingBatch', 'productionOrder', 'product', 'inspector', 'approver', 'results', 'holds', 'supersedes'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('inspected_at', '>=', $v))->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('inspected_at', '<=', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))->when($filters['product_id'] ?? null, fn (Builder $q, $v) => $q->where('product_id', $v))->when($filters['family_id'] ?? null, fn (Builder $q, $v) => $q->whereHas('product', fn (Builder $p) => $p->where('product_family_id', $v)))->when($filters['machine_id'] ?? null, fn (Builder $q, $v) => $q->where('machine_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $x) => $x->where('result', $v)->orWhere('approval_status', $v)))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where('inspection_number', 'like', '%'.$v.'%'))->latest('inspected_at');

        return $this->result($query, $limit, $export,
            ['Inspection', 'Batch / Order', 'Product', 'Stage / Date', 'Inspector', 'Decision', 'Approval', 'QC Accepted', 'QC Rejected', 'Disposition', 'Critical Failure', 'Retest Of', 'Active Hold', 'Approved By / At'],
            fn ($i) => [$i->inspection_number, ($i->curingBatch?->batch_number ?: '—').' / '.($i->productionOrder?->order_number ?: '—'), $i->product?->name ?: '—', str($i->inspection_stage)->headline().' / '.$this->dateTime($i->inspected_at), $i->inspector?->name ?: '—', str($i->result)->headline(), str($i->approval_status)->headline(), $this->q($i->passed_quantity), $this->q($i->failed_quantity), $i->disposition ? str($i->disposition)->headline() : '—', $i->results->where('is_critical', true)->where('result', 'failed')->isNotEmpty() ? 'Yes' : 'No', $i->supersedes?->inspection_number ?: '—', $i->holds->where('status', 'active')->isNotEmpty() ? 'Yes' : 'No', ($i->approver?->name ?: '—').' / '.$this->dateTime($i->approved_at)]);
    }

    private function releases(array $filters, int $limit, bool $export): array
    {
        $query = ProductionCuringRelease::query()->where('company_id', CompanyFeatures::companyId())->with(['batch.productionOrder', 'batch.product.unit', 'batch.releases', 'batch.actions', 'sourceLocation', 'destinationLocation', 'releaser'])
            ->whereHas('batch', fn (Builder $q) => $this->applyBatchAccess($q, $filters))
            ->withCount(['stockMovements as release_out_count' => fn (Builder $q) => $q->where('movement_type', 'curing_release_out'), 'stockMovements as release_in_count' => fn (Builder $q) => $q->where('movement_type', 'curing_release_in')])
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('released_at', '>=', $v))->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('released_at', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $x) => $x->where('release_number', 'like', '%'.$v.'%')->orWhere('posting_reference', 'like', '%'.$v.'%')))->latest('released_at');

        return $this->result($query, $limit, $export,
            ['Release', 'Batch / Order', 'Product', 'Quantity', 'Curing Location', 'Finished Goods Location', 'Released By / At', 'QC Approval', 'Posting Reference', 'Partial / Full', 'Remaining After', 'Ledger Pair'],
            function ($r) {
                $releasedThroughEvent = $r->batch?->releases->filter(fn ($event) => $event->released_at->lt($r->released_at) || ($event->released_at->equalTo($r->released_at) && $event->id <= $r->id))->reduce(fn (string $sum, $event) => bcadd($sum, (string) $event->released_quantity, 12), '0') ?? '0';
                $damagedThroughEvent = $r->batch?->actions->where('action_type', 'damage')->filter(fn ($action) => $action->created_at->lte($r->released_at))->reduce(fn (string $sum, $action) => bcadd($sum, (string) ($action->quantity ?? 0), 12), '0') ?? '0';
                $remaining = bcsub(bcsub((string) ($r->batch?->accepted_quantity ?? 0), $releasedThroughEvent, 12), $damagedThroughEvent, 12);
                if (bccomp($remaining, '0', 12) < 0) {
                    $remaining = '0';
                }

                return [$r->release_number, ($r->batch?->batch_number ?: '—').' / '.($r->batch?->productionOrder?->order_number ?: '—'), $r->batch?->product?->name ?: '—', $this->q($r->released_quantity, $r->batch?->product?->unit?->short_name), $r->sourceLocation?->name ?: '—', $r->destinationLocation?->name ?: '—', ($r->releaser?->name ?: '—').' / '.$this->dateTime($r->released_at), $r->batch?->qc_approved_at ? 'Approved' : 'Pending', $r->posting_reference, bccomp($remaining, '0', 12) === 0 ? 'Full' : 'Partial', $this->q($remaining), ($r->release_out_count === 1 && $r->release_in_count === 1) ? 'Verified' : 'Incomplete'];
            });
    }

    private function machines(array $filters, int $limit, bool $export): array
    {
        $query = Machine::query()->forCurrentCompany()->with(['branch', 'currentMouldInstallation.mould'])->withCount(['productionOrders as order_count' => fn (Builder $q) => $this->applyOrderFilters($q, $filters), 'mouldInstallations as mould_change_count' => fn (Builder $q) => $q->whereNotNull('removed_at')])
            ->withSum(['productionOrders as planned_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'planned_quantity')->withSum(['productionOrders as produced_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'total_produced_quantity')->withSum(['productionOrders as accepted_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'accepted_quantity')->withSum(['productionOrders as rejected_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'rejected_quantity')->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))->when($filters['machine_id'] ?? null, fn (Builder $q, $v) => $q->whereKey($v))->orderBy('name');
        $query->withMax(['productionOrders as last_production_date' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'production_date');

        return $this->result($query, $limit, $export, ['Machine', 'Branch', 'Current Mould', 'Orders', 'Planned', 'Produced', 'Accepted', 'Rejected', 'Scheduled Capacity / Day', 'Output vs Capacity', 'Acceptance Rate', 'Mould Changes', 'Maintenance', 'Last Production'], fn ($m) => [$m->name, $m->branch?->name ?: 'Company-wide', $m->currentMouldInstallation?->mould?->name ?: 'Not installed', $m->order_count, $this->q($m->planned_total), $this->q($m->produced_total), $this->q($m->accepted_total), $this->q($m->rejected_total), $this->q($m->daily_capacity, $m->capacity_unit), $this->percentage($m->produced_total, $m->planned_total), $this->percentage($m->accepted_total, $m->produced_total), $m->mould_change_count, $m->status === 'maintenance' ? 'Under maintenance' : 'Available', $m->last_production_date ?: '—']);
    }

    private function moulds(array $filters, int $limit, bool $export): array
    {
        $orderFilter = fn (Builder $q) => $this->applyOrderFilters($q, $filters);
        $query = ProductionMould::query()->forCurrentCompany()->with(['family', 'currentInstallation.machine'])
            ->withCount(['compatibleMachines', 'installations as installation_count', 'installations as replacement_count' => fn (Builder $q) => $q->where('removal_reason', 'replaced'), 'productionOrders as order_count' => $orderFilter])
            ->withSum(['productionOrders as produced_total' => $orderFilter], 'total_produced_quantity')->withSum(['productionOrders as accepted_total' => $orderFilter], 'accepted_quantity')->withSum(['productionOrders as rejected_total' => $orderFilter], 'rejected_quantity')
            ->withMax('installations as last_installed_at', 'installed_at')->when($filters['mould_id'] ?? null, fn (Builder $q, $v) => $q->whereKey($v))->when($filters['family_id'] ?? null, fn (Builder $q, $v) => $q->where('product_family_id', $v))->orderBy('name');
        $result = $this->result($query, $limit, $export, ['Mould / Code', 'Family', 'Compatible Machines', 'Orders', 'Produced', 'Accepted', 'Rejected', 'Acceptance Rate', 'Expected / Cycle', 'Expected / Day', 'Current Installation', 'Last Installed', 'Maintenance', 'Installations', 'Replacements'], fn ($m) => [($m->name ?: '—').' / '.($m->code ?: '—'), $m->family?->name ?: '—', $m->compatible_machines_count, $m->order_count, $this->q($m->produced_total), $this->q($m->accepted_total), $this->q($m->rejected_total), $this->percentage($m->accepted_total, $m->produced_total), $this->q($m->expected_output_per_cycle), $this->q($m->expected_output_per_day), $m->currentInstallation?->machine?->name ?: 'Not installed', $this->dateTime($m->last_installed_at), $m->under_maintenance ? 'Under maintenance' : ($m->active ? 'Available' : 'Inactive'), $m->installation_count, $m->replacement_count]);

        return $result;
    }

    private function orderQuery(array $filters): Builder
    {
        return $this->applyOrderFilters(ProductionOrder::query()->forCurrentCompany(), $filters);
    }

    private function applyOrderFilters(Builder $q, array $f): Builder
    {
        $user = auth()->user();

        return $q->when($user?->branch_id && ! $user->can('manage cross branch stock locations'), fn (Builder $x) => $x->where(fn (Builder $b) => $b->where('production_orders.branch_id', $user->branch_id)->orWhereNull('production_orders.branch_id')))
            ->when($f['date_from'] ?? null, fn (Builder $x, $v) => $x->whereDate('production_orders.production_date', '>=', $v))->when($f['date_to'] ?? null, fn (Builder $x, $v) => $x->whereDate('production_orders.production_date', '<=', $v))->when($f['branch_id'] ?? null, fn (Builder $x, $v) => $x->where('production_orders.branch_id', $v))->when($f['product_id'] ?? null, fn (Builder $x, $v) => $x->where('production_orders.product_id', $v))->when($f['family_id'] ?? null, fn (Builder $x, $v) => $x->whereHas('product', fn (Builder $p) => $p->where('product_family_id', $v)))->when($f['machine_id'] ?? null, fn (Builder $x, $v) => $x->where('production_orders.machine_id', $v))->when($f['mould_id'] ?? null, fn (Builder $x, $v) => $x->whereHas('assignment', fn (Builder $a) => $a->where('production_mould_id', $v)))->when($f['status'] ?? null, fn (Builder $x, $v) => $x->where('production_orders.status', $v))->when($f['search'] ?? null, fn (Builder $x, $v) => $x->where(fn (Builder $s) => $s->where('production_orders.order_number', 'like', '%'.$v.'%')->orWhereHas('curingBatch', fn (Builder $b) => $b->where('batch_number', 'like', '%'.$v.'%'))));
    }

    private function applyBatchAccess(Builder $q, array $f): Builder
    {
        $user = auth()->user();

        return $q->where('company_id', CompanyFeatures::companyId())->when($user?->branch_id && ! $user->can('manage cross branch stock locations'), fn (Builder $x) => $x->where(fn (Builder $b) => $b->where('branch_id', $user->branch_id)->orWhereNull('branch_id')))->when($f['branch_id'] ?? null, fn (Builder $x, $v) => $x->where('branch_id', $v))->when($f['product_id'] ?? null, fn (Builder $x, $v) => $x->where('product_id', $v))->when($f['family_id'] ?? null, fn (Builder $x, $v) => $x->whereHas('product', fn (Builder $p) => $p->where('product_family_id', $v)))->when($f['machine_id'] ?? null, fn (Builder $x, $v) => $x->where('machine_id', $v));
    }

    private function result(Builder $query, int $limit, bool $export, array $headers, callable $map): array
    {
        $models = $export ? $query->limit($limit)->get() : $query->paginate($limit);
        $rows = $models instanceof LengthAwarePaginator ? $models->getCollection()->map($map)->all() : $models->map($map)->all();

        return ['headers' => $headers, 'rows' => $rows, 'paginator' => $models instanceof LengthAwarePaginator ? $models : null, 'totals' => [], 'truncated' => $export && count($rows) === $limit];
    }

    private function q(mixed $v, ?string $unit = null): string
    {
        if ($v === null) {
            return '—';
        } $n = rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

        return $n.($unit ? ' '.$unit : '');
    }

    private function summaryGroup($order, string $group): string
    {
        return match ($group) {
            'day' => $order->production_date?->format('d M Y') ?: '—', 'product' => $order->product?->name ?: '—', 'family' => $order->product?->productFamily?->name ?: '—', 'machine' => $order->machine?->name ?: '—', 'branch' => $order->branch?->name ?: 'Company-wide', default => 'Details'
        };
    }

    private function materialGroup($material, string $group): string
    {
        return match ($group) {
            'material' => $material->materialProduct?->name ?: $material->name, 'product' => $material->order?->product?->name ?: '—', 'order' => $material->order?->order_number ?: '—', 'day' => $material->order?->production_date?->format('d M Y') ?: '—', default => 'Details'
        };
    }

    private function orderTotalsByUnit(array $filters): array
    {
        $rows = $this->orderQuery($filters)
            ->join('products as report_products', 'report_products.id', '=', 'production_orders.product_id')
            ->leftJoin('units as report_units', 'report_units.id', '=', 'report_products.unit_id')
            ->selectRaw("COALESCE(report_units.short_name, 'unit') AS report_unit, COUNT(production_orders.id) AS order_count, SUM(CASE WHEN production_orders.status = ? THEN 1 ELSE 0 END) AS completed_count, SUM(production_orders.planned_quantity) AS planned, SUM(production_orders.total_produced_quantity) AS produced, SUM(production_orders.accepted_quantity) AS accepted, SUM(production_orders.rejected_quantity) AS rejected", [ProductionOrder::STATUS_COMPLETED])
            ->groupBy('report_units.short_name')->get();
        $totals = ['Orders' => (string) $rows->sum('order_count'), 'Completed orders' => (string) $rows->sum('completed_count')];
        foreach ($rows as $row) {
            foreach (['Planned' => 'planned', 'Produced' => 'produced', 'Accepted' => 'accepted', 'Rejected' => 'rejected'] as $label => $field) {
                $totals[$label.' ('.$row->report_unit.')'] = $this->q($row->{$field});
            }
        }

        return $totals;
    }

    private function materialTotalsByUnit(array $filters, bool $canViewCost): array
    {
        $rows = ProductionOrderMaterial::query()->where('production_order_materials.company_id', CompanyFeatures::companyId())
            ->whereHas('order', fn (Builder $q) => $this->applyOrderFilters($q, $filters))
            ->leftJoin('units as report_units', 'report_units.id', '=', 'production_order_materials.unit_id')
            ->selectRaw("COALESCE(report_units.short_name, 'unit') AS report_unit, SUM(production_order_materials.planned_quantity) AS planned, SUM(production_order_materials.actual_quantity) AS actual, SUM(production_order_materials.planned_cost) AS planned_cost, SUM(production_order_materials.actual_cost) AS actual_cost")
            ->groupBy('report_units.short_name')->get();
        $totals = [];
        foreach ($rows as $row) {
            $totals['Planned material ('.$row->report_unit.')'] = $this->q($row->planned);
            $totals['Actual material ('.$row->report_unit.')'] = $this->q($row->actual);
        }
        if ($canViewCost) {
            $totals['Total planned material cost'] = $this->money($rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) ($row->planned_cost ?? 0), 4), '0'));
            $totals['Total actual material cost'] = $this->money($rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) ($row->actual_cost ?? 0), 4), '0'));
        }

        return $totals;
    }

    private function weightedValue(mixed $cost, mixed $quantity): string
    {
        if ($cost === null || $quantity === null || bccomp((string) $quantity, '0', 12) === 0) {
            return 'Cost unavailable';
        }

        return $this->money(bcdiv((string) $cost, (string) $quantity, 4), 4);
    }

    private function money(mixed $v, int $scale = 2): string
    {
        return $v === null ? 'Cost unavailable' : 'TZS '.number_format((float) $v, $scale);
    }

    private function dateTime(mixed $v): string
    {
        if (! $v) {
            return '—';
        }

        return $v instanceof CarbonInterface ? $v->timezone(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'))->format('d M Y H:i') : (string) $v;
    }

    private function percentage(mixed $n, mixed $d): string
    {
        if ($d === null || bccomp((string) $d, '0', 12) === 0) {
            return 'N/A';
        }

        return number_format((float) bcmul(bcdiv((string) ($n ?? 0), (string) $d, 8), '100', 4), 2).'%';
    }

    private function decimalSumRaw(Collection $items, string $key): string
    {
        return $items->reduce(fn (string $sum, $item) => bcadd($sum, (string) data_get($item, $key, 0), 12), '0');
    }

    private function decimalSum(Collection $items, string $key, int $scale = 4): string
    {
        return number_format((float) $this->decimalSumRaw($items, $key), $scale);
    }

    private function weightedCost(Collection $items, string $costKey, string $quantityKey): string
    {
        $q = $this->decimalSumRaw($items, $quantityKey);

        return bccomp($q, '0', 12) === 0 ? 'N/A' : number_format((float) bcdiv($this->decimalSumRaw($items, $costKey), $q, 4), 4);
    }

    private function quantityByUnit(Collection $orders, string $key): string
    {
        $groups = $orders->groupBy(fn ($o) => $o->product?->unit?->short_name ?: 'unit');
        if ($groups->isEmpty()) {
            return '0';
        }

        return $groups->map(fn ($rows, $unit) => $this->q($this->decimalSumRaw($rows, $key), $unit))->implode(' · ');
    }
}
