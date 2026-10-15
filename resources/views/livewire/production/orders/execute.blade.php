<?php

use App\Models\ProductionOrder;
use App\Models\ProductionOrderMaterial;
use App\Services\ProductionOrderService;
use App\Support\CompanyFeatures;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['order' => null, 'accepted' => '0', 'rejected' => '0', 'notes' => '', 'materials' => []]);
mount(function (ProductionOrder $order): void {
    abort_unless(CompanyFeatures::manufacturingEnabled() && auth()->user()?->can('production.execute_orders'), 403);
    abort_unless((int)$order->company_id === (int)CompanyFeatures::companyId(), 404);
    abort_unless($order->status === ProductionOrder::STATUS_IN_PROGRESS, 409);
    $this->order = $order->load(['materials.unit','product','machine']);
    $this->accepted = (string)$order->accepted_quantity; $this->rejected = (string)$order->rejected_quantity; $this->notes = $order->notes ?? '';
    $this->materials = $order->materials->mapWithKeys(fn($line)=>[$line->id=>['actual_quantity'=>$line->actual_quantity ?? $line->planned_quantity,'actual_cost'=>$line->actual_cost ?? $line->planned_cost]])->all();
});
$usePlanned = function(): void { foreach($this->order->materials as $line){$this->materials[$line->id]['actual_quantity']=$line->planned_quantity;$this->materials[$line->id]['actual_cost']=$line->planned_cost;} };
$saveProgress = function(): void { $this->order = app(ProductionOrderService::class)->saveExecution($this->order,$this->materials,$this->accepted,$this->rejected,$this->notes,auth()->user())->load(['materials.unit','product','machine']); session()->flash('success',__('application.execution_progress_saved_no_stock_was_posted_0b5c39a9')); };
$submit = function(){ $this->saveProgress(); app(ProductionOrderService::class)->submit($this->order,auth()->user()); session()->flash('success',__('application.order_submitted_for_authorised_completion_80be7399')); return $this->redirectRoute('production.orders.show',$this->order,navigate:true); };
$completeProduction = function () {
    Log::info('Complete Production Livewire action invoked', [
        'production_order_id' => $this->order?->id,
        'status' => $this->order?->status,
        'accepted' => $this->accepted,
        'rejected' => $this->rejected,
    ]);

    try {
        $this->order = app(ProductionOrderService::class)->completeExecution(
            $this->order, $this->materials, $this->accepted, $this->rejected, $this->notes, auth()->user()
        );
    } catch (ValidationException $exception) {
        Log::warning('Complete Production validation failed', [
            'production_order_id' => $this->order?->id,
            'errors' => $exception->errors(),
        ]);

        throw $exception;
    }

    Log::info('Complete Production Livewire action completed', ['production_order_id' => $this->order->id]);
    session()->flash('success', __('application.production_completed_and_stock_posted_successfully_0a57daf2'));

    return $this->redirectRoute('production.orders.show', $this->order, navigate: true);
};
?>
<div>
    <x-page-header title="{{ __('application.record_production_execution_0496d6c8') }}" :description="$order->order_number.' · '.$order->product?->name" :breadcrumbs="[__('production.orders.title')=>route('production.orders.index'),$order->order_number=>route('production.orders.show',$order),'Execution'=>null]" />
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div role="alert" data-testid="execution-validation-errors" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><p>{{ __('application.production_could_not_be_completed_b333f359') }}</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form wire:submit="saveProgress" class="space-y-6">
        <x-card title="{{ __('application.record_actual_consumption_d4dec3ca') }}" description="{{ __('application.record_measured_inventory_usage_non_inventory_quantities_and_actual_direct__6d4e7aa3') }}">
            <div class="space-y-3">@foreach($order->materials as $line)<div wire:key="execution-material-{{ $line->id }}" class="grid gap-3 rounded-xl border p-4 md:grid-cols-4"><div><p class="font-black">{{ $line->name }}</p><p class="text-xs text-slate-500">{{ \App\Support\UiText::label($line->line_type) }}</p></div><div><p class="text-xs text-slate-500">{{ __('application.planned_9cbe42aa') }}</p><p>{{ $line->planned_quantity ?? '—' }} {{ $line->unit?->short_name }}@if($line->line_type===ProductionOrderMaterial::TYPE_NON_INVENTORY_COST)<span class="block">TZS {{ number_format((float)$line->planned_cost,2) }}</span>@endif</p></div>@if(in_array($line->line_type,[ProductionOrderMaterial::TYPE_INVENTORY,ProductionOrderMaterial::TYPE_NON_INVENTORY_QUANTITY],true))<x-form-input label="{{ __('application.actual_quantity_bbebbeeb') }}" name="materials.{{ $line->id }}.actual_quantity" type="number" min="0" step="any" wire:model="materials.{{ $line->id }}.actual_quantity" />@else<div></div>@endif @if($line->line_type===ProductionOrderMaterial::TYPE_NON_INVENTORY_COST)<x-form-input label="{{ __('application.actual_cost_f9368485') }}" name="materials.{{ $line->id }}.actual_cost" type="number" min="0" step="0.0001" wire:model="materials.{{ $line->id }}.actual_cost" />@else<div></div>@endif</div>@endforeach</div>
            <button type="button" wire:click="usePlanned" class="mt-4 rounded-xl border px-4 py-2 text-sm font-black">{{ __('application.use_planned_material_quantities_8c44c933') }}</button>
        </x-card>
        <x-card title="{{ __('application.actual_output_9a3dfe47') }}"><div class="grid gap-4 md:grid-cols-2"><x-form-input label="{{ __('application.accepted_products_c5b9a4b1') }}" name="accepted" type="number" min="0" step="0.0001" wire:model="accepted" /><x-form-input label="{{ __('application.rejected_products_990f9e43') }}" name="rejected" type="number" min="0" step="0.0001" wire:model="rejected" /><label class="block text-sm font-bold md:col-span-2">{{ __('application.notes_70440046') }}<textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea></label></div></x-card>
        <div id="complete-production" class="flex flex-wrap justify-end gap-3"><button class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.save_progress_89453f3b') }}</button><button type="button" wire:click="submit" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.submit_for_completion_98182f7a') }}</button>@if(auth()->user()?->can('production.complete_orders'))<button type="button" wire:click="completeProduction" wire:confirm="{{ __('application.post_actual_material_consumption_and_accepted_finished_output_this_cannot_b_d8b5171d') }}" wire:loading.attr="disabled" wire:target="completeProduction" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="completeProduction">{{ __('application.complete_production_a0b15e20') }}</span><span wire:loading wire:target="completeProduction">{{ __('application.completing_production_9eac1496') }}</span></button>@endif</div>
    </form>
</div>
