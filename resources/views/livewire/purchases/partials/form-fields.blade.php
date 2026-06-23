<x-card>
    <form class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Supplier
                <select wire:model="supplier_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    <option value="">Select supplier</option>
                    @foreach (\App\Models\Supplier::where('status', 'active')->orderBy('name')->get() as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                    @foreach (\App\Models\Branch::where('status', 'active')->orderBy('name')->get() as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <x-form-input label="Purchase Date" name="purchase_date" type="date" wire:model="purchase_date" required />
            <x-form-input label="Invoice Number" name="invoice_number" wire:model="invoice_number" />
            <x-form-input label="Reference Number" name="reference_number" wire:model="reference_number" required />
            <x-form-input label="Paid Amount" name="paid_amount" type="number" step="0.01" wire:model.live="paid_amount" required />
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Product</th><th>Qty</th><th>Cost</th><th>Selling Price Update</th><th>Line Total</th><th></th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($items as $index => $item)
                        <tr>
                            <td class="px-3 py-3">
                                <select wire:model="items.{{ $index }}.product_id" class="w-64 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-navy-950">
                                    <option value="">Select product</option>
                                    @foreach (\App\Models\Product::where('status', 'active')->orderBy('name')->get() as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }} / {{ $product->sku }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-3"><input wire:model.live="items.{{ $index }}.ordered_quantity" type="number" step="0.01" class="w-28 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                            <td class="px-3 py-3"><input wire:model.live="items.{{ $index }}.cost_price" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                            <td class="px-3 py-3"><input wire:model="items.{{ $index }}.selling_price" type="number" step="0.01" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                            <td class="px-3 py-3 font-black">TZS {{ number_format((float) ($item['ordered_quantity'] ?? 0) * (float) ($item['cost_price'] ?? 0), 2) }}</td>
                            <td class="px-3 py-3"><button type="button" wire:click="removeItem({{ $index }})" class="text-sm font-bold text-red-600">Remove</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="button" wire:click="addItem" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-black dark:border-slate-700">Add Item</button>

        <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Notes
            <textarea wire:model="notes" class="mt-1 block min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950"></textarea>
        </label>

        @php $total = $this->totalAmount(); @endphp
        <div class="rounded-xl bg-slate-50 p-4 text-right dark:bg-white/5">
            <p class="text-sm text-slate-500">Grand Total</p>
            <p class="text-2xl font-black">TZS {{ number_format($total, 2) }}</p>
            <p class="text-sm text-slate-500">Balance: TZS {{ number_format(max(0, $total - (float) $paid_amount), 2) }}</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="savePurchase('draft')" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Save as Draft</button>
            <button type="button" wire:click="savePurchase('ordered')" class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save as Ordered</button>
            <a href="{{ route('purchases.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black dark:border-slate-700">Cancel</a>
        </div>
    </form>
</x-card>
