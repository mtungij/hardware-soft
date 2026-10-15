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
use App\Support\UiText;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ProductionReportService
{
    public const REPORTS = [
        'summary' => ['title' => 'application.production_summary_47e73931', 'description' => 'application.production_output_by_order_product_machine_mould_and_immutable_recipe_snaps_5fbd26e7'],
        'material-consumption' => ['title' => 'terminology.material_consumption_b1d83558', 'description' => 'application.planned_and_actual_material_usage_and_variance_from_order_snapshots_4ec27478'],
        'costing' => ['title' => 'terminology.production_cost_b877b397', 'description' => 'application.historical_production_costs_and_loss_allocation_from_costing_snapshots_3a3107c4', 'cost' => true],
        'yield-loss' => ['title' => 'terminology.yield_loss_635eb80f', 'description' => 'application.production_yield_rejection_curing_damage_and_released_output_c910e071'],
        'curing' => ['title' => 'application.curing_13ba4ca8', 'description' => 'application.curing_inventory_ageing_quality_approval_and_release_readiness_ef1e82b0'],
        'quality' => ['title' => 'application.quality_control_8f6e9ad9', 'description' => 'application.inspection_outcomes_approvals_critical_failures_retests_and_holds_92ba472a'],
        'releases' => ['title' => 'terminology.finished_goods_releases_a9a9595f', 'description' => 'application.immutable_release_events_and_their_paired_inventory_ledger_postings_16ceb644'],
        'machine-performance' => ['title' => 'terminology.machine_performance_b80c3066', 'description' => 'application.output_versus_scheduled_capacity_acceptance_and_mould_changes_fbfd6aaa'],
        'mould-performance' => ['title' => 'terminology.mould_performance_d0230b85', 'description' => 'application.historical_mould_assignments_output_installations_and_maintenance_state_2750968d'],
    ];

    public function definition(string $report): array
    {
        $definition = self::REPORTS[$report] ?? abort(404);
        $definition['title'] = UiText::translate($definition['title']);
        $definition['description'] = __($definition['description']);

        return $definition;
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
            ['label' => __('application.production_orders_b55b3ce1'), 'value' => (string) $orders->count()],
            ['label' => __('application.planned_quantity_45230c83'), 'value' => $this->quantityByUnit($orders, 'planned_quantity')],
            ['label' => __('terminology.total_produced_cbd1827f'), 'value' => $this->quantityByUnit($orders, 'total_produced_quantity')],
            ['label' => __('terminology.accepted_output_d3d92c02'), 'value' => $this->quantityByUnit($orders, 'accepted_quantity')],
            ['label' => __('terminology.rejected_output_d7600097'), 'value' => $this->quantityByUnit($orders, 'rejected_quantity')],
            ['label' => __('terminology.curing_damage_91eefe76'), 'value' => $this->quantityByUnit($completed, 'curingBatch.damaged_quantity')],
            ['label' => __('terminology.released_finished_goods_cec7ddbf'), 'value' => $this->quantityByUnit($completed, 'curingBatch.released_quantity')],
            ['label' => __('terminology.production_cost_b877b397'), 'value' => ! $canViewCost ? __('terminology.restricted_aa2a08d2') : ($costComplete ? 'TZS '.$this->decimalSum($costings, 'total_actual_cost', 2) : __('terminology.incomplete_costing_data_183332ec'))],
            ['label' => __('terminology.average_cost_accepted_unit_5dd1e3e2'), 'value' => ! $canViewCost ? __('terminology.restricted_aa2a08d2') : ($costComplete ? 'TZS '.$this->weightedCost($costings, 'total_actual_cost', 'accepted_quantity') : __('terminology.cost_unavailable_194b13a4'))],
            ['label' => __('terminology.yield_percentage_2290c080'), 'value' => $this->percentage($this->decimalSumRaw($orders, 'accepted_quantity'), $this->decimalSumRaw($orders, 'total_produced_quantity'))],
            ['label' => __('terminology.machines_used_6da0f608'), 'value' => (string) $orders->pluck('machine_id')->filter()->unique()->count()],
            ['label' => __('terminology.products_produced_49b2f4e8'), 'value' => (string) $orders->pluck('product_id')->filter()->unique()->count()],
        ];
    }

    public function dailyAcceptedChart(array $filters): array
    {
        $orders = $this->orderQuery($filters)->with('product.unit')->orderBy('production_date')->get(['production_date', 'product_id', 'accepted_quantity']);
        $labels = $orders->pluck('production_date')->filter()->map->toDateString()->unique()->values();
        $datasets = $orders->groupBy(fn ($order) => $order->product?->unit?->short_name ?: 'unit')->map(function (Collection $rows, string $unit) use ($labels): array {
            $byDate = $rows->groupBy(fn ($order) => $order->production_date->toDateString());

            return ['label' => __('terminology.accepted_a6aa6f30').$unit.')', 'data' => $labels->map(fn (string $date) => $this->decimalSumRaw($byDate->get($date, collect()), 'accepted_quantity'))->all()];
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
        $query = $this->orderQuery($filters)->with(['branch', 'product.productFamily', 'product.unit', 'machine', 'mould', 'assignment.mould', 'snapshot', 'starter', 'completer']);
        $query = (match ($group) {
            'day' => $query->orderBy('production_orders.production_date'), 'product' => $query->orderBy('production_orders.product_id'), 'family' => $query->orderBy(Product::query()->select('product_family_id')->whereColumn('products.id', 'production_orders.product_id')), 'machine' => $query->orderBy('production_orders.machine_id'), 'branch' => $query->orderBy('production_orders.branch_id'), default => $query->latest('production_orders.production_date')
        })->latest('production_orders.id');
        $headers = [__('application.date_eb9a4bc1'), __('application.order_1d75774c'), __('application.site_5b6825d3'), __('terminology.product_family_26f0d948'), __('application.production_method_92bcfef4'), __('application.machine_65bb1b78'), __('application.mould_1fdffa4c'), __('application.recipe_snapshot_279feaa4'), __('application.planned_9cbe42aa'), __('terminology.produced_5d717336'), __('application.accepted_61a0572c'), __('application.rejected_27eeb7a2'), __('application.status_bae7d5be'), __('terminology.started_completed_09f3e89c'), __('terminology.operators_e9041435')];
        if ($group !== '') {
            $headers = [__('application.group_171a0606'), ...$headers];
        }

        $result = $this->result($query, $limit, $export,
            $headers,
            function ($o) use ($group) {
                $row = [$o->production_date?->locale(app()->getLocale())->translatedFormat('d M Y'), $o->order_number, $o->branch?->name ?: __('application.company_wide_24f7fca3'), ($o->product?->name ?: '—').' / '.($o->product?->productFamily?->name ?: '—'), $o->productionMethodLabel(), $o->machine?->name ?: '—', $o->mould?->name ?: $o->assignment?->mould?->name ?: __('terminology.not_recorded_305cc364'), trim(($o->snapshot?->recipe_name ?: __('terminology.not_captured_70f23bfd')).' '.($o->snapshot?->recipe_version ? 'v'.$o->snapshot->recipe_version : '')), $this->q($o->planned_quantity, $o->product?->unit?->short_name), $this->q($o->total_produced_quantity, $o->product?->unit?->short_name), $this->q($o->accepted_quantity, $o->product?->unit?->short_name), $this->q($o->rejected_quantity, $o->product?->unit?->short_name), UiText::label($o->status), $this->dateTime($o->started_at).' / '.$this->dateTime($o->completed_at), ($o->starter?->name ?: '—').' / '.($o->completer?->name ?: '—')];

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

        $headers = [__('terminology.order_date_05d87dcf'), __('terminology.finished_product_fd0d6bc4'), __('application.raw_material_9f592bac'), __('application.unit_f6b935ab'), __('application.planned_qty_50044aab'), __('terminology.actual_qty_3ca78566'), __('terminology.qty_variance_7f97846c'), __('application.variance_39330351')];
        if ($canViewCost) {
            $headers = [...$headers, __('terminology.planned_unit_cost_d9ad8d67'), __('terminology.actual_unit_cost_50e8638c'), __('terminology.planned_cost_a99bcda5'), __('application.actual_cost_f9368485'), __('terminology.cost_variance_5c5e2f88')];
        }
        $headers[] = __('terminology.raw_material_location_8a1badef');
        if ($group !== '') {
            $headers = [__('application.group_171a0606'), ...$headers];
        }

        $result = $this->result($query, $limit, $export, $headers,
            function ($m) use ($canViewCost, $group) {
                $quantityVariance = bcsub((string) ($m->actual_quantity ?? 0), (string) ($m->planned_quantity ?? 0), 4);
                $actualUnitCost = bccomp((string) ($m->actual_quantity ?? 0), '0', 12) === 1 ? bcdiv((string) $m->actual_cost, (string) $m->actual_quantity, 4) : null;
                $row = [($m->order?->order_number ?: '—').' / '.$m->order?->production_date?->locale(app()->getLocale())->translatedFormat('d M Y'), $m->order?->product?->name ?: '—', $m->materialProduct?->name ?: $m->name, $m->unit?->short_name ?: '—', $this->q($m->planned_quantity), $this->q($m->actual_quantity), $this->q($quantityVariance), $this->percentage($quantityVariance, $m->planned_quantity)];
                if ($canViewCost) {
                    $row = [...$row, $m->unit_cost === null ? __('terminology.cost_unavailable_194b13a4') : $this->money($m->unit_cost), $actualUnitCost === null ? __('terminology.cost_unavailable_194b13a4') : $this->money($actualUnitCost), $this->money($m->planned_cost), $this->money($m->actual_cost), $this->money(bcsub((string) $m->actual_cost, (string) $m->planned_cost, 4))];
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
            [__('terminology.costing_490452e4'), __('terminology.order_product_c24fe1a3'), __('application.date_eb9a4bc1'), __('application.status_bae7d5be'), __('terminology.planned_inventory_127dd9e9'), __('terminology.actual_inventory_3f39d742'), __('terminology.planned_non_inventory_a254adb8'), __('terminology.actual_non_inventory_f91d1d2a'), __('terminology.total_planned_0160f26a'), __('terminology.total_actual_6d42d938'), __('terminology.rejected_loss_6a412bcf'), __('terminology.curing_damage_loss_67afb7cc'), __('terminology.accepted_unit_cost_a9f51538'), __('terminology.sellable_unit_cost_3f751468'), __('application.variance_eb39bbf6'), __('application.variance_39330351'), __('terminology.missing_cost_d25e3e69'), __('terminology.finalised_88460574')],
            fn ($c) => [$c->costing_number, ($c->productionOrder?->order_number ?: '—').' / '.($c->productionOrder?->product?->name ?: '—'), $c->productionOrder?->production_date?->locale(app()->getLocale())->translatedFormat('d M Y'), UiText::label($c->status), $this->money($c->planned_inventory_material_cost), $this->money($c->actual_inventory_material_cost), $this->money($c->planned_non_inventory_cost), $this->money($c->actual_non_inventory_cost), $this->money($c->total_planned_cost), $this->money($c->total_actual_cost), $this->money($c->rejected_loss_cost), $this->money($c->curing_damage_loss_cost), $this->money($c->cost_per_accepted_unit, 4), $this->money($c->cost_per_released_unit, 4), $this->money($c->cost_variance), $this->q($c->variance_percentage).'%', $c->has_missing_cost ? __('application.yes_5397e058') : __('application.no_816c52fd'), $this->dateTime($c->finalized_at).' / '.($c->finalizer?->name ?: '—')]);
        $totals = (clone $query)->reorder()->selectRaw('SUM(total_actual_cost) AS actual_cost, SUM(rejected_loss_cost) AS rejected_loss, SUM(curing_damage_loss_cost) AS damage_loss, SUM(accepted_quantity) AS accepted_qty, SUM(released_quantity) AS released_qty')->first();
        $result['totals'] = [
            __('terminology.total_actual_production_cost_e13d4d84') => $this->money($totals?->actual_cost),
            __('terminology.total_rejected_loss_1eed851f') => $this->money($totals?->rejected_loss),
            __('terminology.total_curing_damage_loss_df68afec') => $this->money($totals?->damage_loss),
            __('terminology.weighted_accepted_unit_cost_678e0cdd') => $this->weightedValue($totals?->actual_cost, $totals?->accepted_qty),
            __('terminology.weighted_sellable_unit_cost_29da3f1c') => $this->weightedValue($totals?->actual_cost, $totals?->released_qty),
        ];

        return $result;
    }

    private function yieldLoss(array $filters, int $limit, bool $export): array
    {
        $query = $this->orderQuery($filters)->with(['product.unit', 'curingBatch', 'qualityInspections'])->latest('production_date')->latest('id');

        return $this->result($query, $limit, $export,
            [__('terminology.order_product_c24fe1a3'), __('application.planned_quantity_45230c83'), __('terminology.produced_5d717336'), __('terminology.accepted_into_curing_498ab9ce'), __('terminology.production_reject_71299028'), 'QC Reject', __('terminology.curing_damage_91eefe76'), __('application.released_quantity_ed7b277d'), __('terminology.remaining_curing_0ac375f7'), __('terminology.yield_e49e1389'), 'QC Pass Rate', 'QC Failure Rate', __('terminology.rework_quantity_a07f71bb'), __('terminology.quarantined_quantity_04f1a980'), __('terminology.total_recorded_loss_af56a39f')],
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
            [__('terminology.batch_order_41676b2c'), __('application.product_dd3b86d1'), __('terminology.site_machine_03c2d0da'), __('terminology.curing_destination_7c7643b7'), __('terminology.accepted_into_curing_498ab9ce'), __('terminology.production_reject_71299028'), 'QC Reject', __('terminology.curing_damage_91eefe76'), __('application.released_35d9bc51'), __('application.remaining_cc632b5e'), __('terminology.release_eligible_a2ec77ed'), __('terminology.curing_start_213c1967'), __('terminology.earliest_sellable_f7e37cea'), __('application.full_curing_f59aa9ce'), __('terminology.age_ff9f1ff3'), __('terminology.ageing_bucket_f482515a'), __('application.progress_1b90271d'), 'QC Approval', __('application.quarantine_7f983c16'), __('terminology.active_hold_8f5b6033'), __('application.status_bae7d5be')],
            function ($b) {
                $u = $b->product?->unit?->short_name;
                $elapsed = max(0, $b->curing_started_at?->diffInDays(now()) ?? 0);
                $total = max(1, $b->curing_started_at?->diffInDays($b->full_curing_at) ?? 1);
                $progress = min(100, (int) floor(($elapsed / $total) * 100));
                $ageBucket = bccomp((string) $b->remaining_quantity, '0', 12) === 1 && now()->gt($b->full_curing_at) ? __('terminology.past_full_curing_unreleased_42ffee6c') : (bccomp((string) $b->remaining_quantity, '0', 12) === 1 && now()->gt($b->minimum_sellable_at) ? __('terminology.past_earliest_release_unreleased_73c16aac') : ($elapsed < 3 ? __('terminology.less_than_3_days_4779b27e') : ($elapsed <= 7 ? '3–7 '.__('date.day_unit') : ($elapsed <= 14 ? '8–14 '.__('date.day_unit') : __('terminology.more_than_14_days_688377a8')))));

                return [($b->batch_number ?: '—').' / '.($b->productionOrder?->order_number ?: '—'), $b->product?->name ?: '—', ($b->branch?->name ?: __('application.company_wide_24f7fca3')).' / '.($b->machine?->name ?: '—'), ($b->sourceLocation?->name ?: '—').' / '.($b->defaultReleaseLocation?->name ?: '—'), $this->q($b->accepted_quantity, $u), $this->q($b->productionOrder?->rejected_quantity, $u), $this->q($b->qc_rejected_quantity, $u), $this->q($b->damaged_quantity, $u), $this->q($b->released_quantity, $u), $this->q($b->remaining_quantity, $u), $this->q($b->release_eligible_quantity, $u), $this->dateTime($b->curing_started_at), $this->dateTime($b->minimum_sellable_at), $this->dateTime($b->full_curing_at), $elapsed.' '.__('date.day_unit'), $ageBucket, $progress.'%', $b->qc_approved_at ? __('application.approved_41b81eb8') : __('application.pending_96f608c1'), $b->status === ProductionCuringBatch::STATUS_QUARANTINED ? __('application.yes_5397e058') : __('application.no_816c52fd'), $b->qualityHolds->where('status', 'active')->isNotEmpty() ? __('application.yes_5397e058') : __('application.no_816c52fd'), UiText::label($b->resolvedStatus())];
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
            [__('terminology.inspection_d3ce4618'), __('terminology.batch_order_41676b2c'), __('application.product_dd3b86d1'), __('terminology.stage_date_e1fb3944'), __('application.inspector_8222c902'), __('application.decision_7f59a1f1'), __('application.approval_8cc047ac'), __('application.qc_accepted_1f7f91a2'), __('application.qc_rejected_a47b36d2'), __('application.disposition_009ce48f'), __('terminology.critical_failure_03da1eca'), __('terminology.retest_of_8e83dc09'), __('terminology.active_hold_8f5b6033'), __('terminology.approved_by_at_5da719ae')],
            fn ($i) => [$i->inspection_number, ($i->curingBatch?->batch_number ?: '—').' / '.($i->productionOrder?->order_number ?: '—'), $i->product?->name ?: '—', UiText::label($i->inspection_stage).' / '.$this->dateTime($i->inspected_at), $i->inspector?->name ?: '—', UiText::label($i->result), UiText::label($i->approval_status), $this->q($i->passed_quantity), $this->q($i->failed_quantity), $i->disposition ? UiText::label($i->disposition) : '—', $i->results->where('is_critical', true)->where('result', 'failed')->isNotEmpty() ? __('application.yes_5397e058') : __('application.no_816c52fd'), $i->supersedes?->inspection_number ?: '—', $i->holds->where('status', 'active')->isNotEmpty() ? __('application.yes_5397e058') : __('application.no_816c52fd'), ($i->approver?->name ?: '—').' / '.$this->dateTime($i->approved_at)]);
    }

    private function releases(array $filters, int $limit, bool $export): array
    {
        $query = ProductionCuringRelease::query()->where('company_id', CompanyFeatures::companyId())->with(['batch.productionOrder', 'batch.product.unit', 'batch.releases', 'batch.actions', 'sourceLocation', 'destinationLocation', 'releaser'])
            ->whereHas('batch', fn (Builder $q) => $this->applyBatchAccess($q, $filters))
            ->withCount(['stockMovements as release_out_count' => fn (Builder $q) => $q->where('movement_type', 'curing_release_out'), 'stockMovements as release_in_count' => fn (Builder $q) => $q->where('movement_type', 'curing_release_in')])
            ->when($filters['date_from'] ?? null, fn (Builder $q, $v) => $q->whereDate('released_at', '>=', $v))->when($filters['date_to'] ?? null, fn (Builder $q, $v) => $q->whereDate('released_at', '<=', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $x) => $x->where('release_number', 'like', '%'.$v.'%')->orWhere('posting_reference', 'like', '%'.$v.'%')))->latest('released_at');

        return $this->result($query, $limit, $export,
            [__('application.release_d41f56ce'), __('terminology.batch_order_41676b2c'), __('application.product_dd3b86d1'), __('application.quantity_44f6af69'), __('application.curing_location_72020a95'), __('terminology.finished_goods_location_4040cb87'), __('terminology.released_by_at_84311926'), 'QC Approval', __('application.posting_reference_9df2e1e5'), __('terminology.partial_full_60e7fca8'), __('terminology.remaining_after_8d280688'), __('terminology.ledger_pair_52528627')],
            function ($r) {
                $releasedThroughEvent = $r->batch?->releases->filter(fn ($event) => $event->released_at->lt($r->released_at) || ($event->released_at->equalTo($r->released_at) && $event->id <= $r->id))->reduce(fn (string $sum, $event) => bcadd($sum, (string) $event->released_quantity, 12), '0') ?? '0';
                $damagedThroughEvent = $r->batch?->actions->where('action_type', 'damage')->filter(fn ($action) => $action->created_at->lte($r->released_at))->reduce(fn (string $sum, $action) => bcadd($sum, (string) ($action->quantity ?? 0), 12), '0') ?? '0';
                $remaining = bcsub(bcsub((string) ($r->batch?->accepted_quantity ?? 0), $releasedThroughEvent, 12), $damagedThroughEvent, 12);
                if (bccomp($remaining, '0', 12) < 0) {
                    $remaining = '0';
                }

                return [$r->release_number, ($r->batch?->batch_number ?: '—').' / '.($r->batch?->productionOrder?->order_number ?: '—'), $r->batch?->product?->name ?: '—', $this->q($r->released_quantity, $r->batch?->product?->unit?->short_name), $r->sourceLocation?->name ?: '—', $r->destinationLocation?->name ?: '—', ($r->releaser?->name ?: '—').' / '.$this->dateTime($r->released_at), $r->batch?->qc_approved_at ? __('application.approved_41b81eb8') : __('application.pending_96f608c1'), $r->posting_reference, bccomp($remaining, '0', 12) === 0 ? __('terminology.full_10b28a8c') : __('application.partial_65de2e2a'), $this->q($remaining), ($r->release_out_count === 1 && $r->release_in_count === 1) ? __('terminology.verified_aed3b8c6') : __('terminology.incomplete_387fd1bb')];
            });
    }

    private function machines(array $filters, int $limit, bool $export): array
    {
        $query = Machine::query()->forCurrentCompany()->with(['branch', 'currentMouldInstallation.mould'])->withCount(['productionOrders as order_count' => fn (Builder $q) => $this->applyOrderFilters($q, $filters), 'mouldInstallations as mould_change_count' => fn (Builder $q) => $q->whereNotNull('removed_at')])
            ->withSum(['productionOrders as planned_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'planned_quantity')->withSum(['productionOrders as produced_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'total_produced_quantity')->withSum(['productionOrders as accepted_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'accepted_quantity')->withSum(['productionOrders as rejected_total' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'rejected_quantity')->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))->when($filters['machine_id'] ?? null, fn (Builder $q, $v) => $q->whereKey($v))->orderBy('name');
        $query->withMax(['productionOrders as last_production_date' => fn (Builder $q) => $this->applyOrderFilters($q, $filters)], 'production_date');

        return $this->result($query, $limit, $export, [__('application.machine_65bb1b78'), __('application.branch_1627510b'), __('terminology.current_mould_478bf5d6'), __('application.orders_cded0933'), __('application.planned_9cbe42aa'), __('terminology.produced_5d717336'), __('application.accepted_61a0572c'), __('application.rejected_27eeb7a2'), __('terminology.scheduled_capacity_day_8d917506'), __('terminology.output_vs_capacity_ab76acba'), __('terminology.acceptance_rate_7e1c9bb0'), __('terminology.mould_changes_e21b7bc1'), __('application.maintenance_94de303b'), __('terminology.last_production_7fd067b6')], fn ($m) => [$m->name, $m->branch?->name ?: __('application.company_wide_24f7fca3'), $m->currentMouldInstallation?->mould?->name ?: __('application.not_installed_1aab9f18'), $m->order_count, $this->q($m->planned_total), $this->q($m->produced_total), $this->q($m->accepted_total), $this->q($m->rejected_total), $this->q($m->daily_capacity, $m->capacity_unit), $this->percentage($m->produced_total, $m->planned_total), $this->percentage($m->accepted_total, $m->produced_total), $m->mould_change_count, $m->status === 'maintenance' ? __('terminology.under_maintenance_1989efd9') : __('application.available_7c62a142'), $m->last_production_date ?: '—']);
    }

    private function moulds(array $filters, int $limit, bool $export): array
    {
        $orderFilter = fn (Builder $q) => $this->applyOrderFilters($q, $filters);
        $query = ProductionMould::query()->forCurrentCompany()->with(['family', 'currentInstallation.machine'])
            ->withCount(['compatibleMachines', 'installations as installation_count', 'installations as replacement_count' => fn (Builder $q) => $q->where('removal_reason', 'replaced'), 'productionOrders as order_count' => $orderFilter])
            ->withSum(['productionOrders as produced_total' => $orderFilter], 'total_produced_quantity')->withSum(['productionOrders as accepted_total' => $orderFilter], 'accepted_quantity')->withSum(['productionOrders as rejected_total' => $orderFilter], 'rejected_quantity')
            ->withMax('installations as last_installed_at', 'installed_at')->when($filters['mould_id'] ?? null, fn (Builder $q, $v) => $q->whereKey($v))->when($filters['family_id'] ?? null, fn (Builder $q, $v) => $q->where('product_family_id', $v))->orderBy('name');
        $result = $this->result($query, $limit, $export, [__('terminology.mould_code_758389e0'), __('application.family_4efb6cb7'), __('application.compatible_machines_4b1815cd'), __('application.orders_cded0933'), __('terminology.produced_5d717336'), __('application.accepted_61a0572c'), __('application.rejected_27eeb7a2'), __('terminology.acceptance_rate_7e1c9bb0'), __('terminology.expected_cycle_c20b4ae2'), __('terminology.expected_day_1b0a915b'), __('application.current_installation_197d307c'), __('terminology.last_installed_f60301d1'), __('application.maintenance_94de303b'), __('terminology.installations_e5426e1f'), __('terminology.replacements_844abd7b')], fn ($m) => [($m->name ?: '—').' / '.($m->code ?: '—'), $m->family?->name ?: '—', $m->compatible_machines_count, $m->order_count, $this->q($m->produced_total), $this->q($m->accepted_total), $this->q($m->rejected_total), $this->percentage($m->accepted_total, $m->produced_total), $this->q($m->expected_output_per_cycle), $this->q($m->expected_output_per_day), $m->currentInstallation?->machine?->name ?: __('application.not_installed_1aab9f18'), $this->dateTime($m->last_installed_at), $m->under_maintenance ? __('terminology.under_maintenance_1989efd9') : ($m->active ? __('application.available_7c62a142') : __('application.inactive_09af574c')), $m->installation_count, $m->replacement_count]);

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
            'day' => $order->production_date?->locale(app()->getLocale())->translatedFormat('d M Y') ?: '—', 'product' => $order->product?->name ?: '—', 'family' => $order->product?->productFamily?->name ?: '—', 'machine' => $order->machine?->name ?: '—', 'branch' => $order->branch?->name ?: __('application.company_wide_24f7fca3'), default => __('application.details_dc3decbb')
        };
    }

    private function materialGroup($material, string $group): string
    {
        return match ($group) {
            'material' => $material->materialProduct?->name ?: $material->name, 'product' => $material->order?->product?->name ?: '—', 'order' => $material->order?->order_number ?: '—', 'day' => $material->order?->production_date?->locale(app()->getLocale())->translatedFormat('d M Y') ?: '—', default => __('application.details_dc3decbb')
        };
    }

    private function orderTotalsByUnit(array $filters): array
    {
        $rows = $this->orderQuery($filters)
            ->join('products as report_products', 'report_products.id', '=', 'production_orders.product_id')
            ->leftJoin('units as report_units', 'report_units.id', '=', 'report_products.unit_id')
            ->selectRaw("COALESCE(report_units.short_name, 'unit') AS report_unit, COUNT(production_orders.id) AS order_count, SUM(CASE WHEN production_orders.status = ? THEN 1 ELSE 0 END) AS completed_count, SUM(production_orders.planned_quantity) AS planned, SUM(production_orders.total_produced_quantity) AS produced, SUM(production_orders.accepted_quantity) AS accepted, SUM(production_orders.rejected_quantity) AS rejected", [ProductionOrder::STATUS_COMPLETED])
            ->groupBy('report_units.short_name')->get();
        $totals = [__('application.orders_cded0933') => (string) $rows->sum('order_count'), __('terminology.completed_orders_b3feb297') => (string) $rows->sum('completed_count')];
        foreach ($rows as $row) {
            foreach ([__('application.planned_9cbe42aa') => 'planned', __('terminology.produced_5d717336') => 'produced', __('application.accepted_61a0572c') => 'accepted', __('application.rejected_27eeb7a2') => 'rejected'] as $label => $field) {
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
            $totals[__('terminology.planned_material_ea9259e6').$row->report_unit.')'] = $this->q($row->planned);
            $totals[__('terminology.actual_material_0a72f96b').$row->report_unit.')'] = $this->q($row->actual);
        }
        if ($canViewCost) {
            $totals[__('terminology.total_planned_material_cost_e6004da6')] = $this->money($rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) ($row->planned_cost ?? 0), 4), '0'));
            $totals[__('terminology.total_actual_material_cost_175bbc6f')] = $this->money($rows->reduce(fn (string $sum, $row) => bcadd($sum, (string) ($row->actual_cost ?? 0), 4), '0'));
        }

        return $totals;
    }

    private function weightedValue(mixed $cost, mixed $quantity): string
    {
        if ($cost === null || $quantity === null || bccomp((string) $quantity, '0', 12) === 0) {
            return __('terminology.cost_unavailable_194b13a4');
        }

        return $this->money(bcdiv((string) $cost, (string) $quantity, 4), 4);
    }

    private function money(mixed $v, int $scale = 2): string
    {
        return $v === null ? __('terminology.cost_unavailable_194b13a4') : 'TZS '.number_format((float) $v, $scale);
    }

    private function dateTime(mixed $v): string
    {
        if (! $v) {
            return '—';
        }

        return $v instanceof CarbonInterface ? $v->timezone(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'))->locale(app()->getLocale())->translatedFormat('d M Y H:i') : (string) $v;
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
