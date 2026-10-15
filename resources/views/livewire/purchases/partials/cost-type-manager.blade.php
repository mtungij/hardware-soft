@if (auth()->user()?->hasAnyRole(['Super Admin', 'Admin']))
    <div class="mb-4 text-right">
        <button type="button" x-on:click="$dispatch('open-modal', 'purchase-cost-types')" class="text-xs font-bold text-cyan-700 dark:text-cyan-300">{{ __('application.manage_cost_types_43b03326') }}</button>
    </div>
    <x-modal name="purchase-cost-types" maxWidth="md">
        <div class="p-5">
            <h2 class="text-lg font-black">{{ __('application.manage_cost_types_43b03326') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('application.types_added_here_can_be_selected_on_any_purchase_line_302ba940') }}</p>
            <div class="mt-4 flex flex-wrap items-end gap-2">
                <label class="flex-1 text-xs font-bold">{{ __('application.cost_type_name_a1c59a16') }}
                    <input wire:model="new_cost_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                </label>
                <button type="button" wire:click="addCostType" class="rounded-lg bg-cyan-700 px-3 py-2 text-xs font-bold text-white">{{ __('application.add_type_de185074') }}</button>
            </div>
            @error('new_cost_type') <p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p> @enderror
            <p class="mt-4 text-xs text-slate-500">{{ __('application.available_4a8c2fb2') }} {{ \App\Models\PurchaseCostType::query()->where('is_active', true)->orderBy('name')->pluck('name')->join(', ') }}</p>
            <div class="mt-5 text-right"><button type="button" x-on:click="$dispatch('close-modal', 'purchase-cost-types')" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700">{{ __('application.close_bbfa773e') }}</button></div>
        </div>
    </x-modal>
@endif
