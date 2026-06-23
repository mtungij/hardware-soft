<x-card>
    <form class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <x-form-input label="Transfer Number" name="transfer_number" wire:model="transfer_number" required />
            <x-form-input label="Transfer Date" name="transfer_date" type="date" wire:model="transfer_date" required />
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">From Location
                <select wire:model="from_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach (\App\Models\StockLocation::where('type', 'store')->where('status', 'active')->orderBy('name')->get() as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">To Location
                <select wire:model="to_location_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach (\App\Models\StockLocation::where('type', 'dispensing')->where('status', 'active')->orderBy('name')->get() as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <x-table :headers="['Product', 'Available Main Store', 'Unit', 'Transfer Qty', 'Notes', '']">
            @foreach ($items as $index => $item)
                @php
                    $product = $item['product_id'] ? \App\Models\Product::with('unit')->find($item['product_id']) : null;
                    $available = $this->availableQuantity($item['product_id']);
                @endphp
                <tr>
                    <td class="px-4 py-3">
                        <select wire:model.live="items.{{ $index }}.product_id" class="w-72 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                            <option value="">Select product</option>
                            @foreach (\App\Models\Product::where('status', 'active')->orderBy('name')->get() as $productOption)
                                <option value="{{ $productOption->id }}">{{ $productOption->name }} / {{ $productOption->sku }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td class="px-4 py-3 font-black">{{ number_format($available, 2) }}</td>
                    <td class="px-4 py-3">{{ $product?->unit?->short_name ?? '-' }}</td>
                    <td class="px-4 py-3"><input wire:model="items.{{ $index }}.quantity" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                    <td class="px-4 py-3"><input wire:model="items.{{ $index }}.notes" class="w-56 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                    <td class="px-4 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">Remove</button></td>
                </tr>
            @endforeach
        </x-table>

        <button type="button" wire:click="addItem" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Add Item</button>

        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
            <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
        </label>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="saveTransfer('draft')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Save as Draft</button>
            <button type="button" wire:click="saveTransfer('completed')" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Complete Transfer</button>
            <a href="{{ route('stock-transfers.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
        </div>
    </form>
</x-card>
