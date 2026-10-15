<tr wire:key="purchase-cost-breakdown-{{ $index }}" class="bg-slate-50/80 dark:bg-white/5">
    <td colspan="{{ $breakdownColspan }}" class="px-4 py-4">
        @php
            $breakdownRows = $item['cost_breakdown'] ?? [];
            $breakdownTotal = $this->breakdownTotal($index);
            $purchaseUnitCost = (float) ($item['cost_price'] ?? 0);
            $remaining = $this->breakdownRemaining($index);
            $money = fn ($amount) => \App\Support\NumberFormatter::money($amount);
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-navy-950">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="font-black">{{ __('application.cost_breakdown_per_139401f8') }} {{ $selectedPurchaseUnit?->short_name ?: 'purchase unit' }}</h3>
                    <p class="text-xs text-slate-500">{{ __('application.explain_the_unit_cost_already_entered_for_one_purchase_unit_5b308aae') }}</p>
                </div>
                <button type="button" wire:click="addCostComponent({{ $index }})" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-slate-700">{{ __('application.add_component_30c6678d') }}</button>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-3" aria-live="polite">
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.unit_cost_to_explain_072d6fb7') }}</p><p class="font-black">TZS {{ $money($purchaseUnitCost) }}</p></div>
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-xs text-slate-500">{{ __('application.breakdown_entered_d08035c3') }}</p><p class="font-black">TZS {{ $money($breakdownTotal) }}</p></div>
                <div class="rounded-lg p-3 {{ $remaining < 0 ? 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200' : 'bg-slate-50 dark:bg-white/5' }}">
                    <p class="text-xs">{{ $remaining < 0 ? __('terminology.exceeded_by_e9b43ad6') : __('application.remaining_cc632b5e') }}</p>
                    <p class="font-black">TZS {{ $money(abs($remaining)) }}</p>
                </div>
            </div>

            @foreach ($breakdownRows as $rowIndex => $component)
                <div wire:key="purchase-cost-component-{{ $index }}-{{ $rowIndex }}" class="mt-3 grid gap-2 sm:grid-cols-[1.1fr_0.8fr_1fr_1fr_auto]">
                    <label class="text-xs font-bold">{{ __('application.cost_type_64e3d203') }}
                        <select wire:model.blur="items.{{ $index }}.cost_breakdown.{{ $rowIndex }}.type_id" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-2 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">{{ __('application.select_type_905d0122') }}</option>
                            @foreach ($purchaseCostTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-xs font-bold">{{ __('application.amount_per_unit_tzs_fdd7f6ec') }}
                        <input wire:model.live.debounce.300ms="items.{{ $index }}.cost_breakdown.{{ $rowIndex }}.amount" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-2 dark:border-slate-700 dark:bg-navy-950">
                    </label>
                    <label class="text-xs font-bold">{{ __('application.reference_optional_e3039b84') }}
                        <input wire:model.blur="items.{{ $index }}.cost_breakdown.{{ $rowIndex }}.reference" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-2 dark:border-slate-700 dark:bg-navy-950">
                    </label>
                    <label class="text-xs font-bold">{{ __('application.notes_optional_4d56ca9b') }}
                        <input wire:model.blur="items.{{ $index }}.cost_breakdown.{{ $rowIndex }}.notes" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-2 dark:border-slate-700 dark:bg-navy-950">
                    </label>
                    <button type="button" wire:click="removeCostComponent({{ $index }}, {{ $rowIndex }})" class="self-end rounded-lg border border-red-200 px-2 py-2 text-xs font-bold text-red-700">{{ __('application.remove_e963907d') }}</button>
                    @foreach (['type_id', 'amount', 'reference', 'notes'] as $field)
                        @error("items.{$index}.cost_breakdown.{$rowIndex}.{$field}") <p class="text-xs font-semibold text-red-600 sm:col-span-5">{{ $message }}</p> @enderror
                    @endforeach
                </div>
            @endforeach

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-bold {{ $remaining === 0.0 && $breakdownRows !== [] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}" aria-live="polite">
                    @if ($breakdownRows !== [])
                        @if ($remaining > 0)
                            {{ __('application.remaining_tzs_6ab9a980') }} {{ $money($remaining) }} {{ __('application.is_unexplained_cost_breakdown_is_incomplete_c83f6117') }}
                        @elseif ($remaining < 0)
                            {{ __('application.breakdown_exceeds_unit_cost_by_tzs_5b767e01') }} {{ $money(abs($remaining)) }}{{ __('application.cost_breakdown_is_incomplete_d0c53f8f') }}
                        @else
                            {{ __('application.cost_breakdown_is_complete_75646de9') }}
                        @endif
                    @else
                        {{ __('application.add_a_component_to_explain_this_unit_cost_601b28a1') }}
                    @endif
                </p>
                <button type="button" wire:click="completeCostBreakdown({{ $index }})" @disabled($breakdownRows === [] || $purchaseUnitCost <= 0 || abs($remaining) >= 0.005) class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-black text-white disabled:cursor-not-allowed disabled:opacity-40">{{ __('application.complete_cost_breakdown_0b640478') }}</button>
            </div>
        </div>
    </td>
</tr>
