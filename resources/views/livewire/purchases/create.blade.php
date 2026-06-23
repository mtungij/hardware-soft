<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'branch_id' => '',
    'supplier_id' => '',
    'purchase_date' => '',
    'invoice_number' => '',
    'reference_number' => '',
    'notes' => '',
    'paid_amount' => '0',
    'items' => [],
]);

mount(function (InventoryService $inventory) {
    $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::where('code', 'MAIN')->value('id'));
    $this->purchase_date = now()->toDateString();
    $this->reference_number = $inventory->generatePurchaseReference();
    $this->items = [['product_id' => '', 'ordered_quantity' => '1', 'cost_price' => '0', 'selling_price' => '', 'line_total' => 0]];
});

$addItem = function () {
    $this->items[] = ['product_id' => '', 'ordered_quantity' => '1', 'cost_price' => '0', 'selling_price' => '', 'line_total' => 0];
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
};

$totalAmount = function () {
    return collect($this->items)->sum(fn ($item) => (float) ($item['ordered_quantity'] ?? 0) * (float) ($item['cost_price'] ?? 0));
};

$savePurchase = function (string $status) {
    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'supplier_id' => ['required', 'exists:suppliers,id'],
        'purchase_date' => ['required', 'date'],
        'invoice_number' => ['nullable', 'string', 'max:255'],
        'reference_number' => ['required', 'string', 'max:255', 'unique:purchases,reference_number'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'paid_amount' => ['required', 'numeric', 'min:0'],
        'items' => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'exists:products,id'],
        'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0'],
        'items.*.cost_price' => ['required', 'numeric', 'min:0'],
        'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
    ]);

    $total = $this->totalAmount();

    if ((float) $validated['paid_amount'] > $total) {
        throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed total amount.']);
    }

    DB::transaction(function () use ($validated, $status, $total) {
        $paid = (float) $validated['paid_amount'];
        $balance = max(0, $total - $paid);

        $purchase = Purchase::create([
            'branch_id' => $validated['branch_id'],
            'supplier_id' => $validated['supplier_id'],
            'purchase_date' => $validated['purchase_date'],
            'invoice_number' => $validated['invoice_number'],
            'reference_number' => $validated['reference_number'],
            'status' => $status,
            'payment_status' => $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => $balance,
            'notes' => $validated['notes'],
            'created_by' => auth()->id(),
        ]);

        foreach ($validated['items'] as $item) {
            $quantity = (float) $item['ordered_quantity'];
            $cost = (float) $item['cost_price'];

            $purchase->items()->create([
                'product_id' => $item['product_id'],
                'ordered_quantity' => $quantity,
                'received_quantity' => 0,
                'cost_price' => $cost,
                'selling_price' => $item['selling_price'] ?: null,
                'line_total' => $quantity * $cost,
            ]);

            if ($item['selling_price'] !== '' && $item['selling_price'] !== null) {
                Product::whereKey($item['product_id'])->update(['selling_price' => $item['selling_price']]);
            }
        }
    });

    session()->flash('success', 'Purchase saved successfully.');
    $this->redirectRoute('purchases.index', navigate: true);
};

?>

<div>
    <x-page-header title="Create Purchase" description="Create a draft or ordered purchase. Stock is not increased until receiving." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Create' => null]" />

    <x-card>
        <form class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Supplier
                    <select wire:model="supplier_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        <option value="">Select supplier</option>
                        @foreach (Supplier::where('status', 'active')->orderBy('name')->get() as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <span class="text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
                </label>
                <label class="block text-sm font-bold text-slate-700 dark:text-slate-200">Branch
                    <select wire:model="branch_id" class="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-navy-950">
                        @foreach (Branch::where('status', 'active')->orderBy('name')->get() as $branch)
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
                                        @foreach (Product::where('status', 'active')->orderBy('name')->get() as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }} / {{ $product->sku }}</option>
                                        @endforeach
                                    </select>
                                    @error("items.{$index}.product_id") <span class="block text-xs font-semibold text-red-600">{{ $message }}</span> @enderror
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

            <div class="rounded-xl bg-slate-50 p-4 text-right dark:bg-white/5">
                @php $total = $this->totalAmount(); @endphp
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
</div>
