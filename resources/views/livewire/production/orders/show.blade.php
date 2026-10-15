<?php

use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Models\ProductionCuringBatch;
use App\Models\StockMovement;
use App\Services\ProductionCostingService;
use App\Services\ProductionOrderService;
use App\Support\CompanyFeatures;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['order' => null, 'cancellationReason' => '']);
mount(function (ProductionOrder $order): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && collect(['production.view_orders','production.create_orders','production.execute_orders','production.complete_orders','production.cancel_orders'])->contains(fn($p)=>auth()->user()?->can($p)),403);
    abort_unless((int)$order->company_id === (int)CompanyFeatures::companyId(),404);
    $this->order=$order->load(['machine','mould','product','branch','rawMaterialLocation','curingLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','curingBatch.qualityInspections','costing','qualityInspections','snapshot.outputUnit','materials.materialProduct','materials.unit','creator','starter','completer','canceller']);
});
$startProduction=function():void{$this->order=app(ProductionOrderService::class)->start($this->order,auth()->user())->load(['machine','product','branch','rawMaterialLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','snapshot.outputUnit','materials.materialProduct','materials.unit','starter']);session()->flash('success',__('application.production_started_planned_quantities_copied_as_actual_defaults_no_stock_po_38d4e2e3'));};
$complete=function():void{$this->order=app(ProductionOrderService::class)->complete($this->order,auth()->user())->load(['machine','mould','product','branch','rawMaterialLocation','curingLocation','finishedGoodsLocation','productionOutputLocation','finalFinishedGoodsLocation','curingBatch.qualityInspections','costing','qualityInspections','snapshot.outputUnit','materials.materialProduct','materials.unit','completer']);session()->flash('success',__('application.production_completed_and_inventory_posted_exactly_once_3bebd1fd'));};
$cancel=function():void{$this->order=app(ProductionOrderService::class)->cancel($this->order,$this->cancellationReason,auth()->user());session()->flash('success',__('application.production_order_cancelled_without_stock_movement_88526c3f'));};
$viewCosting=function(){
    $costing=$this->order->costing;
    if(!$costing){$costing=app(ProductionCostingService::class)->calculate($this->order,auth()->user());}
    return $this->redirectRoute('production.costing.show',$costing,navigate:true);
};
?>
<div>
    @php
        $completionIssues = $order->status === ProductionOrder::STATUS_AWAITING_COMPLETION
            ? app(ProductionOrderService::class)->completionIssues($order, auth()->user())
            : [];
    @endphp
    <x-page-header :title="$order->order_number"  :translate-title="false" :description="$order->product?->name.' · '.$order->productionMethodLabel()" :breadcrumbs="[__('production.orders.title')=>route('production.orders.index'),$order->order_number=>null]">
        @if($order->status==='in_progress' && auth()->user()?->can('production.execute_orders'))<a href="{{ route('production.orders.execute',$order) }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.record_actual_consumption_d4dec3ca') }}</a>@if(auth()->user()?->can('production.complete_orders'))<a href="{{ route('production.orders.execute',$order) }}#complete-production" wire:navigate class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white">{{ __('application.complete_production_a0b15e20') }}</a>@endif @endif
        @if($order->status===ProductionOrder::STATUS_AWAITING_COMPLETION && $completionIssues === [])<button wire:click="complete" wire:confirm="{{ __('application.post_actual_material_consumption_and_accepted_finished_output_this_cannot_b_d8b5171d') }}" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white">{{ __('application.complete_post_stock_f8f2f9b4') }}</button>@endif
        @if($order->status==='completed')
            @if($order->curingBatch && auth()->user()?->canAny(['production.view_curing','production.manage_curing','production.release_curing']))<a href="{{ route('production.curing.show',$order->curingBatch) }}" wire:navigate class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-black text-white">{{ __('application.view_curing_batch_db3c4f97') }}</a>@endif
            @if($order->costing && auth()->user()?->canAny(['production.view_costing','production.manage_costing','production.finalize_costing']))<a href="{{ route('production.costing.show',$order->costing) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.view_costing_95e7d7fd') }}</a>@elseif(auth()->user()?->can('production.manage_costing'))<button wire:click="viewCosting" class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.view_costing_95e7d7fd') }}</button>@endif
            @if(auth()->user()?->canAny(['production.view_quality','production.perform_quality_inspections','production.approve_quality']))<a href="{{ route('production.quality.inspections.index',['search'=>$order->order_number]) }}" wire:navigate class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.view_qc_c3be997d') }}</a>@endif
            <button type="button" onclick="window.print()" class="rounded-xl border px-4 py-2.5 text-sm font-black print:hidden">{{ __('application.print_production_order_89f8255b') }}</button>
        @endif
    </x-page-header>
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php
        $curingStatus=$order->curingBatch?->resolvedStatus();
        $hasQc=$order->qualityInspections->isNotEmpty() || ($order->curingBatch?->qualityInspections?->isNotEmpty() ?? false);
        $currentStage=match(true){
            $order->status==='planned'=>'planned',
            in_array($order->status,['in_progress','awaiting_completion'],true)=>'in_progress',
            $order->status==='completed' && $order->curingBatch && in_array($curingStatus,[ProductionCuringBatch::STATUS_RELEASED,ProductionCuringBatch::STATUS_CLOSED],true)=>'released',
            $order->status==='completed' && $order->curingBatch && $curingStatus===ProductionCuringBatch::STATUS_READY_FOR_RELEASE=>'ready_for_release',
            $order->status==='completed' && $hasQc=>'qc',
            $order->status==='completed' && $order->curingBatch=>'curing',
            $order->status==='completed'=>'completed',
            default=>'planned',
        };
        $stages=['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','curing'=>'Curing','qc'=>'QC','ready_for_release'=>'Ready For Release','released'=>'Released'];
        $currentIndex=array_search($currentStage,array_keys($stages),true);
    @endphp
    <x-card title="{{ __('application.production_progress_76a3f62b') }}" class="mb-6 print:hidden">
        <ol class="grid gap-2 sm:grid-cols-7">@foreach($stages as $key=>$label)@php $index=$loop->index; @endphp<li class="relative rounded-xl border px-3 py-3 text-center {{ $index===$currentIndex?'border-cyan-500 bg-cyan-50 text-cyan-900 ring-2 ring-cyan-500 dark:bg-cyan-950/40 dark:text-cyan-100':($index<$currentIndex?'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200':'border-slate-200 text-slate-400 dark:border-slate-700') }}"><span class="block text-xs font-bold uppercase">{{ $index<$currentIndex?__('application.completed_1798b3ba'):($index===$currentIndex?__('application.current_4fc0e2bc'):__('application.pending_96f608c1')) }}</span><span class="font-black">{{ $label }}</span></li>@endforeach</ol>
    </x-card>
    @if($order->status===ProductionOrder::STATUS_PLANNED)
        <x-card title="{{ __('application.next_production_action_c2768b31') }}" description="{{ __('application.start_this_planned_order_to_record_actual_consumption_and_production_output_c0a04ac0') }}" class="mb-6">
            @if(auth()->user()?->can('production.execute_orders'))
                <button
                    type="button"
                    wire:click="startProduction"
                    wire:loading.attr="disabled"
                    wire:target="startProduction"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-cyan-600 bg-cyan-500 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:border-cyan-700 hover:bg-cyan-600 active:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-cyan-400 dark:bg-cyan-500 dark:text-slate-950 dark:hover:border-cyan-300 dark:hover:bg-cyan-400 dark:active:bg-cyan-300 dark:focus:ring-offset-slate-950"
                >
                    <span wire:loading.remove wire:target="startProduction">{{ __('production.orders.actions.start_production') }}</span>
                    <span wire:loading wire:target="startProduction">{{ __('production.orders.actions.starting_production') }}</span>
                </button>
                <p class="mt-2 text-xs text-slate-500">{{ __('application.any_stock_machine_location_or_recipe_snapshot_issue_will_be_shown_here_afte_dcb72290') }}</p>
            @else
                <p class="text-sm font-bold text-amber-700 dark:text-amber-300">{{ __('application.you_need_the_production_order_execution_permission_to_start_this_order_a1dec82e') }}</p>
            @endif
        </x-card>
    @endif
    @if($order->status===ProductionOrder::STATUS_AWAITING_COMPLETION)
        @php
            $authorizationOnly = array_keys($completionIssues) === ['authorization'];
            $completionCardTitle = $completionIssues === []
                ? 'Next Production Action'
                : ($authorizationOnly ? 'Awaiting Authorized Completion' : 'Cannot Complete Production');
        @endphp
        <x-card :title="$completionCardTitle" description="{{ __('application.post_actual_consumption_and_accepted_output_exactly_once_c1c30934') }}" class="mb-6">
            @if($completionIssues === [])
                <button wire:click="complete" wire:confirm="{{ __('application.post_actual_material_consumption_and_accepted_finished_output_this_cannot_b_d8b5171d') }}" wire:loading.attr="disabled" wire:target="complete" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50">
                    <span wire:loading.remove wire:target="complete">{{ __('application.complete_amp_post_stock_36738939') }}</span>
                    <span wire:loading wire:target="complete">{{ __('application.posting_stock_2259a7a4') }}</span>
                </button>
            @else
                <p class="text-sm font-bold text-amber-700 dark:text-amber-300">{{ __('application.completion_is_currently_blocked_for_the_following_reason_1bdf1818') }}{{ count($completionIssues) === 1 ? '' : 's' }}:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-700 dark:text-amber-300">
                    @foreach($completionIssues as $issue)<li>{{ $issue }}</li>@endforeach
                </ul>
            @endif
        </x-card>
    @endif
    <div class="grid gap-6 xl:grid-cols-3">
        <x-card title="{{ __('application.order_summary_c7df8a99') }}"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>{{ __('application.status_bae7d5be') }}</dt><dd class="font-black">{{ \App\Support\UiText::label($order->status) }}</dd></div><div class="flex justify-between"><dt>{{ __('application.production_method_92bcfef4') }}</dt><dd class="font-black">{{ $order->productionMethodLabel() }}</dd></div><div class="flex justify-between"><dt>{{ __('application.machine_65bb1b78') }}</dt><dd>{{ $order->machine?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>{{ __('application.mould_1fdffa4c') }}</dt><dd>{{ $order->mould?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>{{ __('application.date_eb9a4bc1') }}</dt><dd>{{ $order->production_date->locale(app()->getLocale())->translatedFormat('d M Y') }}</dd></div><div class="flex justify-between"><dt>{{ __('application.branch_1627510b') }}</dt><dd>{{ $order->branch?->name ?: '—' }}</dd></div><div class="flex justify-between"><dt>{{ __('application.raw_location_5cc55a47') }}</dt><dd>{{ $order->rawMaterialLocation?->name }}</dd></div><div class="flex justify-between"><dt>{{ __('application.initial_output_location_a79a42a0') }}</dt><dd>{{ $order->productionOutputLocation?->name ?: $order->finishedGoodsLocation?->name }}</dd></div><div class="flex justify-between"><dt>{{ __('application.final_sellable_location_60978f6d') }}</dt><dd>{{ $order->finalFinishedGoodsLocation?->name ?: $order->finishedGoodsLocation?->name }}</dd></div><div class="flex justify-between"><dt>{{ __('application.planned_9cbe42aa') }}</dt><dd>{{ $order->planned_quantity }}</dd></div><div class="flex justify-between"><dt>{{ __('application.accepted_61a0572c') }}</dt><dd>{{ $order->accepted_quantity }}</dd></div><div class="flex justify-between"><dt>{{ __('application.rejected_27eeb7a2') }}</dt><dd>{{ $order->rejected_quantity }}</dd></div></dl>@if($order->curingBatch && auth()->user()?->canAny(['production.view_curing','production.manage_curing','production.release_curing']))<a href="{{ route('production.curing.show',$order->curingBatch) }}" wire:navigate class="mt-4 inline-flex rounded-lg bg-amber-500 px-3 py-2 text-xs font-black text-white">{{ __('application.view_curing_batch_db3c4f97') }}</a>@endif</x-card>
        <x-card title="{{ __('application.immutable_recipe_snapshot_a27a6e0e') }}"><p class="font-black">{{ $order->snapshot?->recipe_name }}</p><p class="text-sm text-slate-500">{{ __('application.version_2da600bf') }} {{ $order->snapshot?->recipe_version ?: '—' }} {{ __('application.captured_e7c4d0b4') }} {{ $order->snapshot?->captured_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') }}</p><p class="mt-3 text-xs font-bold text-amber-700">{{ __('application.later_recipe_edits_do_not_change_this_order_cd6b8f05') }}</p></x-card>
        <x-card title="{{ __('application.posting_b5bb5e2a') }}"><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>{{ __('application.reference_db1c7845') }}</dt><dd>{{ $order->posting_reference ?: __('terminology.not_posted_47050962') }}</dd></div><div class="flex justify-between"><dt>{{ __('application.posted_301777ff') }}</dt><dd>{{ $order->posted_at?->locale(app()->getLocale())->translatedFormat('d M Y H:i') ?: '—' }}</dd></div><div class="flex justify-between"><dt>{{ __('application.completed_by_c30c0263') }}</dt><dd>{{ $order->completer?->name ?: '—' }}</dd></div></dl><p class="mt-3 text-xs text-slate-500">{{ __('application.rejected_output_is_retained_here_and_never_added_to_sellable_stock_13beee3d') }}</p>@if($order->costing && auth()->user()?->canAny(['production.view_costing','production.manage_costing','production.finalize_costing']))<a href="{{ route('production.costing.show',$order->costing) }}" wire:navigate class="mt-4 inline-flex rounded-lg border px-3 py-2 text-xs font-black">{{ __('application.view_costing_95e7d7fd') }}</a>@endif</x-card>
    </div>
    @php $availability = app(ProductionOrderService::class)->availability($order); @endphp
    <x-card title="{{ __('application.planned_and_actual_requirements_85ed06c2') }}" class="mt-6">
        <x-table :headers="[__('terminology.line_ea967600'),__('application.type_3deb7456'),__('terminology.normalized_39208c59'),__('application.planned_9cbe42aa'),__('application.actual_7bb2ee60'),__('terminology.available_at_raw_location_3076846b'),__('application.cost_64ae43e8')]">
            @foreach($order->materials as $line)<tr><td class="px-4 py-3 font-black">{{ $line->name }}</td><td class="px-4 py-3">{{ \App\Support\UiText::label($line->line_type) }}</td><td class="px-4 py-3">{{ $line->normalized_quantity_per_output ?? '—' }}</td><td class="px-4 py-3">{{ $line->planned_quantity ?? '—' }} {{ $line->unit?->short_name }}</td><td class="px-4 py-3">{{ $line->actual_quantity ?? '—' }} {{ $line->unit?->short_name }}</td><td class="px-4 py-3">@if($line->line_type===ProductionOrderMaterial::TYPE_INVENTORY){{ $availability[$line->id] ?? 0 }} <span class="{{ bccomp((string)($availability[$line->id]??0),(string)($line->actual_quantity??$line->planned_quantity),4)>=0?'text-emerald-700':'text-red-700' }}">{{ bccomp((string)($availability[$line->id]??0),(string)($line->actual_quantity??$line->planned_quantity),4)>=0?__('terminology.sufficient_970ce113'):__('application.shortage_7ce60ebb') }}</span>@else—@endif</td><td class="px-4 py-3">TZS {{ number_format((float)($line->actual_cost ?: $line->planned_cost),2) }}</td></tr>@endforeach
        </x-table>
    </x-card>
    @if(auth()->user()?->can('production.cancel_orders') && !in_array($order->status,['completed','cancelled']))
        <x-card title="{{ __('application.cancel_unposted_order_092922cd') }}" class="mt-6"><div class="flex flex-col gap-3 sm:flex-row"><input wire:model.blur="cancellationReason" placeholder="{{ __('application.cancellation_reason_required_9abb9158') }}" class="flex-1 rounded-lg border-slate-200 dark:bg-navy-950"><button wire:click="cancel" wire:confirm="{{ __('application.cancel_this_order_without_posting_stock_afb4c93e') }}" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-black text-white">{{ __('application.cancel_order_87125442') }}</button></div>@error('cancellation_reason')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror</x-card>
    @endif
    @php $movements=StockMovement::query()->where('reference_type',ProductionOrder::class)->where('reference_id',$order->id)->get(); @endphp
    @if($movements->isNotEmpty())<x-card title="{{ __('application.stock_movement_references_a1942ab8') }}" class="mt-6"><x-table :headers="[__('application.type_3deb7456'),__('application.product_dd3b86d1'),__('application.location_d219c681'),__('terminology.in_aef36502'),__('terminology.out_220e06ba')]">@foreach($movements as $movement)<tr><td class="px-4 py-3">{{ \App\Support\UiText::label($movement->movement_type) }}</td><td class="px-4 py-3">{{ $movement->product?->name }}</td><td class="px-4 py-3">{{ $movement->stockLocation?->name }}</td><td class="px-4 py-3">{{ $movement->quantity_in }}</td><td class="px-4 py-3">{{ $movement->quantity_out }}</td></tr>@endforeach</x-table></x-card>@endif
</div>
