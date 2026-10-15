<x-card>
    <form class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-form-input label="{{ __('application.transfer_number_dee1c37f') }}" name="transfer_number" wire:model="transfer_number" required />
            <x-form-input label="{{ __('application.transfer_date_30db6f99') }}" name="transfer_date" type="date" wire:model="transfer_date" required />
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.from_location_e1dee3f1') }}
                <select wire:model="from_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach ($this->transferSourceLocations() as $location)
                        <option value="{{ $location->id }}">{{ \App\Support\InventorySettings::stockLocationLabel($location) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.to_location_232dc136') }}
                <select wire:model="to_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach ($this->transferDestinationLocations() as $location)
                        <option value="{{ $location->id }}">{{ \App\Support\InventorySettings::stockLocationLabel($location) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <x-table :headers="[__('application.product_dd3b86d1'), __('terminology.available_source_800a0d6a'), __('application.unit_f6b935ab'), __('application.transfer_qty_b82a634d'), __('application.notes_70440046'), '']">
            @foreach ($items as $index => $item)
                @php
                    $product = $item['product_id'] ? \App\Models\Product::with(['unit', 'size'])->find($item['product_id']) : null;
                    $available = $this->availableQuantity($item['product_id']);
                @endphp
                <tr>
                    <td class="px-4 py-3">
                        <select wire:model.live="items.{{ $index }}.product_id" class="w-72 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">{{ __('application.select_product_596d90e5') }}</option>
                            @foreach (\App\Models\Product::with('size')->where('status', 'active')->orderBy('name')->get() as $productOption)
                                <option value="{{ $productOption->id }}">{{ $productOption->displayNameWithSize() }} / {{ $productOption->sku }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="px-4 py-3 font-black">{{ \App\Support\NumberFormatter::quantity($available) }}</td>
                    <td class="px-4 py-3">{{ $product?->unit?->short_name ?? '-' }}</td>
                    <td class="px-4 py-3"><input wire:model="items.{{ $index }}.quantity" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                    <td class="px-4 py-3"><input wire:model="items.{{ $index }}.notes" class="w-56 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                    <td class="px-4 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></td>
                </tr>
            @endforeach
        </x-table>

        <button type="button" wire:click="addItem" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">{{ __('application.add_item_4dba3be5') }}</button>

        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">{{ __('application.notes_70440046') }}
            <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
        </label>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="saveTransfer('draft')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.save_as_draft_c031325d') }}</button>
            <button type="button" wire:click="saveTransfer('completed')" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.complete_transfer_bf7f6e01') }}</button>
            <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a>
        </div>
    </form>
</x-card>
