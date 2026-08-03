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
$saveProgress = function(): void { $this->order = app(ProductionOrderService::class)->saveExecution($this->order,$this->materials,$this->accepted,$this->rejected,$this->notes,auth()->user())->load(['materials.unit','product','machine']); session()->flash('success','Execution progress saved. No stock was posted.'); };
$submit = function(){ $this->saveProgress(); app(ProductionOrderService::class)->submit($this->order,auth()->user()); session()->flash('success','Order submitted for authorised completion.'); return $this->redirectRoute('production.orders.show',$this->order,navigate:true); };
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
    session()->flash('success', 'Production completed and stock posted successfully.');

    return $this->redirectRoute('production.orders.show', $this->order, navigate: true);
};
?>
<div>
    <x-page-header title="Record Production Execution" :description="$order->order_number.' · '.$order->product?->name" :breadcrumbs="[__('production.orders.title')=>route('production.orders.index'),$order->order_number=>route('production.orders.show',$order),'Execution'=>null]" />
    @if(session('success'))<div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div role="alert" data-testid="execution-validation-errors" class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-bold text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><p>Production could not be completed:</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form wire:submit="saveProgress" class="space-y-6">
        <x-card title="Record Actual Consumption" description="Record measured inventory usage, non-inventory quantities, and actual direct costs.">
            <div class="space-y-3">@foreach($order->materials as $line)<div wire:key="execution-material-{{ $line->id }}" class="grid gap-3 rounded-xl border p-4 md:grid-cols-4"><div><p class="font-black">{{ $line->name }}</p><p class="text-xs text-slate-500">{{ str($line->line_type)->headline() }}</p></div><div><p class="text-xs text-slate-500">Planned</p><p>{{ $line->planned_quantity ?? '—' }} {{ $line->unit?->short_name }}@if($line->line_type===ProductionOrderMaterial::TYPE_NON_INVENTORY_COST)<span class="block">TZS {{ number_format((float)$line->planned_cost,2) }}</span>@endif</p></div>@if(in_array($line->line_type,[ProductionOrderMaterial::TYPE_INVENTORY,ProductionOrderMaterial::TYPE_NON_INVENTORY_QUANTITY],true))<x-form-input label="Actual Quantity" name="materials.{{ $line->id }}.actual_quantity" type="number" min="0" step="any" wire:model="materials.{{ $line->id }}.actual_quantity" />@else<div></div>@endif @if($line->line_type===ProductionOrderMaterial::TYPE_NON_INVENTORY_COST)<x-form-input label="Actual Cost" name="materials.{{ $line->id }}.actual_cost" type="number" min="0" step="0.0001" wire:model="materials.{{ $line->id }}.actual_cost" />@else<div></div>@endif</div>@endforeach</div>
            <button type="button" wire:click="usePlanned" class="mt-4 rounded-xl border px-4 py-2 text-sm font-black">Use Planned Material Quantities</button>
        </x-card>
        <x-card title="Actual Output"><div class="grid gap-4 md:grid-cols-2"><x-form-input label="Accepted Products" name="accepted" type="number" min="0" step="0.0001" wire:model="accepted" /><x-form-input label="Rejected Products" name="rejected" type="number" min="0" step="0.0001" wire:model="rejected" /><label class="block text-sm font-bold md:col-span-2">Notes<textarea wire:model="notes" class="mt-1 block min-h-20 w-full rounded-lg border-slate-200 dark:bg-navy-950"></textarea></label></div></x-card>
        <div id="complete-production" class="flex flex-wrap justify-end gap-3"><button class="rounded-xl border px-4 py-2.5 text-sm font-black">Save Progress</button><button type="button" wire:click="submit" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Submit for Completion</button>@if(auth()->user()?->can('production.complete_orders'))<button type="button" wire:click="completeProduction" wire:confirm="Post actual material consumption and accepted finished output? This cannot be edited afterward." wire:loading.attr="disabled" wire:target="completeProduction" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-black text-white disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="completeProduction">Complete Production</span><span wire:loading wire:target="completeProduction">Completing Production...</span></button>@endif</div>
    </form>
</div>
