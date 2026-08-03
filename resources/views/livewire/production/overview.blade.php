<?php

use App\Models\Machine;
use App\Models\ProductionMachineAssignment;
use App\Models\ProductionCuringBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderCosting;
use App\Models\ProductionQualityHold;
use App\Models\ProductionQualityInspection;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;

layout('layouts.app');

mount(fn () => abort_unless(
    CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.view'),
    403
));

?>

<div>
    <x-page-header
        :title="__('production.title')"
        description="Machine availability and today's real production schedule."
        :breadcrumbs="['Dashboard' => route('dashboard'), __('production.title') => null]"
    >
        @canany(['production.view_product_families', 'production.manage_product_families'])
            <a href="{{ route('production.product-families.index') }}" wire:navigate class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.product_families.title') }}</a>
        @endcanany
        @canany(['production.view_moulds', 'production.manage_moulds'])
            <a href="{{ route('production.moulds.index') }}" wire:navigate class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800">{{ __('production.moulds.title') }}</a>
        @endcanany
        <a href="{{ route('production.machines.index') }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('production.machines') }}</a>
        <a href="{{ route('production.schedule.index') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('production.daily_schedule') }}</a>
    </x-page-header>

    @php
        $today = CompanyFeatures::localDate();
        $activeMachines = Machine::query()->forCurrentCompany()->active()->count();
        $maintenanceMachines = Machine::query()->forCurrentCompany()->where('status', 'maintenance')->count();
        $todayPlannedMachineIds = ProductionMachineAssignment::query()->forCurrentCompany()
            ->whereDate('production_date', $today)->whereNot('status', 'cancelled')->pluck('machine_id')->unique();
        $todayAssignments = ProductionMachineAssignment::query()->forCurrentCompany()
            ->with(['machine', 'product', 'branch'])->whereDate('production_date', $today)
            ->orderBy('planned_start_time')->get();
    @endphp

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Active Machines', $activeMachines, 'Available equipment'],
            ['Under Maintenance', $maintenanceMachines, 'Not schedulable'],
            ["Today's Planned Machines", $todayPlannedMachineIds->count(), $today],
            ["Today's Unassigned Active", max(0, $activeMachines - $todayPlannedMachineIds->count()), 'Active and not scheduled'],
        ] as [$label, $value, $caption])
            <x-card><p class="text-sm font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-black">{{ $value }}</p><p class="mt-1 text-xs text-slate-500">{{ $caption }}</p></x-card>
        @endforeach
    </div>

    @can('production.view_curing')
        @php
            $curingNow = now(CompanyFeatures::currentCompany()?->timezone ?: config('app.timezone'));
            $curingQuery = fn () => ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo(auth()->user());
            $stillCuring = $curingQuery()->whereNotIn('status', ['quarantined','closed','released'])->where('minimum_sellable_at','>',$curingNow)->where('remaining_quantity','>',0)->count();
            $eligibleToday = $curingQuery()->whereNotIn('status', ['quarantined','closed','released'])->where('minimum_sellable_at','<=',$curingNow)->where('remaining_quantity','>',0)->count();
            $awaitingQuantity = $curingQuery()->whereNotIn('status', ['closed','released'])->sum('remaining_quantity');
            $fullyCured = $curingQuery()->where('full_curing_at','<=',$curingNow)->where('remaining_quantity','>',0)->count();
            $quarantined = $curingQuery()->where('status','quarantined')->count();
        @endphp
        <div class="mb-6">
            <div class="mb-3 flex items-center justify-between"><h2 class="text-lg font-black">Curing Overview</h2><a href="{{ route('production.curing.index') }}" wire:navigate class="text-sm font-black text-build-orange">Open Curing Yard</a></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([['Batches Still Curing',$stillCuring],['Eligible for Release Today',$eligibleToday],['Quantity Awaiting Release',number_format((float)$awaitingQuantity,4)],['Fully Cured Batches',$fullyCured],['Quarantined Batches',$quarantined]] as [$label,$value])
                    <x-card><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-black">{{ $value }}</p></x-card>
                @endforeach
            </div>
        </div>
    @endcan

    @can('production.view_quality')
        @php
            $qualityInspection = fn () => ProductionQualityInspection::query()->forCurrentCompany()->accessibleTo(auth()->user());
            $qualityHold = fn () => ProductionQualityHold::query()->forCurrentCompany()->accessibleTo(auth()->user());
            $qualityBatch = fn () => ProductionCuringBatch::query()->forCurrentCompany()->accessibleTo(auth()->user());
            $pendingApproval = $qualityInspection()->where('approval_status','pending')->whereIn('result',['passed','conditional'])->count();
            $failedInspections = $qualityInspection()->where('result','failed')->count();
            $activeHolds = $qualityHold()->active()->count();
            $awaitingInspection = $qualityBatch()->where('remaining_quantity','>',0)->whereHas('product',fn($q)=>$q->where('requires_pre_release_inspection',true))->whereDoesntHave('qualityInspections',fn($q)=>$q->where('inspection_stage','pre_release')->where('result','passed')->where('approval_status','approved'))->count();
            $readyForRelease = $qualityBatch()->where('remaining_quantity','>',0)->whereDoesntHave('qualityHolds',fn($q)=>$q->active())->whereHas('qualityInspections',fn($q)=>$q->where('inspection_stage','pre_release')->where('result','passed')->where('approval_status','approved'))->count();
        @endphp
        <div class="mb-6"><div class="mb-3 flex items-center justify-between"><h2 class="text-lg font-black">Quality Control</h2><a href="{{route('production.quality.inspections.index')}}" wire:navigate class="text-sm font-black text-build-orange">Open Quality Control</a></div><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">@foreach([['Inspections Pending Approval',$pendingApproval],['Failed Inspections',$failedInspections],['Active Quality Holds',$activeHolds],['Batches Awaiting Pre-Release Inspection',$awaitingInspection],['Approved Batches Ready for Release',$readyForRelease]] as [$label,$value])<x-card><p class="text-xs font-bold text-slate-500">{{$label}}</p><p class="mt-2 text-2xl font-black">{{$value}}</p></x-card>@endforeach</div></div>
    @endcan

    @can('production.view_costing')
        @php
            $costingUser = auth()->user();
            $orderScope = fn () => ProductionOrder::query()->forCurrentCompany()
                ->when($costingUser?->branch_id && !$costingUser->can('manage cross branch stock locations'),fn($q)=>$q->where(fn($b)=>$b->where('branch_id',$costingUser->branch_id)->orWhereNull('branch_id')));
            $costScope = fn () => ProductionOrderCosting::query()->forCurrentCompany()->accessibleTo($costingUser);
            $uncosted = $orderScope()->where('status',ProductionOrder::STATUS_COMPLETED)->doesntHave('costing')->count();
            $provisional = $costScope()->where('status',ProductionOrderCosting::STATUS_CALCULATED)->count();
            $finalizedCostings = $costScope()->where('status',ProductionOrderCosting::STATUS_FINALIZED)->count();
            $monthlyLoss = $costScope()->whereMonth('calculated_at',now()->month)->whereYear('calculated_at',now()->year)->sum('total_loss_cost');
            $averageAcceptedCost = $costScope()->whereNotNull('cost_per_accepted_unit')->avg('cost_per_accepted_unit');
        @endphp
        <div class="mb-6">
            <div class="mb-3 flex items-center justify-between"><h2 class="text-lg font-black">Production Costing</h2><a href="{{ route('production.costing.index') }}" wire:navigate class="text-sm font-black text-build-orange">Open Costing</a></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach([['Uncosted Completed Orders',$uncosted],['Provisional Costings',$provisional],['Finalized Costings',$finalizedCostings],['Production Loss This Month',number_format((float)$monthlyLoss,2)],['Average Accepted Unit Cost',$averageAcceptedCost!==null?number_format((float)$averageAcceptedCost,4):'—']] as [$label,$value])
                    <x-card><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-black">{{ $value }}</p></x-card>
                @endforeach
            </div>
        </div>
    @endcan

    <x-card title="Today's Schedule" description="Planning records only; no stock movement is created.">
        <x-table :headers="['Machine', 'Product', 'Target', 'Time', 'Branch', 'Status']">
            @forelse ($todayAssignments as $assignment)
                <tr>
                    <td class="px-4 py-3 font-black">{{ $assignment->machine?->name }}</td>
                    <td class="px-4 py-3">{{ $assignment->product?->name }}</td>
                    <td class="px-4 py-3">{{ $assignment->target_quantity !== null ? number_format((float) $assignment->target_quantity, 2) : '—' }}</td>
                    <td class="px-4 py-3">{{ $assignment->planned_start_time ?: '—' }} – {{ $assignment->planned_end_time ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $assignment->branch?->name ?: 'Company-wide' }}</td>
                    <td class="px-4 py-3">{{ ucfirst($assignment->status) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No assignments planned for today.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
