<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state(['purchase' => null, 'branch_id' => '', 'supplier_id' => '', 'purchase_date' => '', 'invoice_number' => '', 'reference_number' => '', 'notes' => '', 'paid_amount' => '0', 'items' => []]);

mount(function (Purchase $purchase) {
    abort_unless($purchase->canBeModified(), 403);

    $this->purchase = $purchase->load('items');
    $this->branch_id = (string) $purchase->branch_id;
    $this->supplier_id = (string) $purchase->supplier_id;
    $this->purchase_date = $purchase->purchase_date->toDateString();
    $this->invoice_number = $purchase->invoice_number;
    $this->reference_number = $purchase->reference_number;
    $this->notes = $purchase->notes;
    $this->paid_amount = (string) $purchase->paid_amount;
    $this->items = $purchase->items->map(fn ($item) => [
        'id' => $item->id,
        'product_id' => (string) $item->product_id,
        'ordered_quantity' => (string) $item->ordered_quantity,
        'cost_price' => (string) $item->cost_price,
        'selling_price' => (string) $item->selling_price,
    ])->all();
});

$addItem = function () {
    if (blank($this->supplier_id)) {
        $this->addError('supplier_id', 'Select supplier before adding products.');

        return;
    }

    $this->items[] = ['id' => null, 'product_id' => '', 'ordered_quantity' => '1', 'cost_price' => '0', 'selling_price' => ''];
};

$removeItem = function (int $index) {
    unset($this->items[$index]);
    $this->items = array_values($this->items);
};

$syncProductSellingPrice = function (int $index) {
    $productId = $this->items[$index]['product_id'] ?? null;
    $product = $productId ? Product::query()->find($productId) : null;

    $this->items[$index]['selling_price'] = $product ? (string) $product->selling_price : '';
};

$totalAmount = function () {
    return collect($this->items)->sum(fn ($item) => (float) ($item['ordered_quantity'] ?? 0) * (float) ($item['cost_price'] ?? 0));
};

$savePurchase = function (string $status) {
    abort_unless($this->purchase->canBeModified(), 403);

    $validated = $this->validate([
        'branch_id' => ['required', 'exists:branches,id'],
        'supplier_id' => ['required', 'exists:suppliers,id'],
        'purchase_date' => ['required', 'date'],
        'invoice_number' => ['nullable', 'string', 'max:255'],
        'reference_number' => ['required', 'string', 'max:255', Rule::unique('purchases', 'reference_number')->ignore($this->purchase->id)],
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
        $this->purchase->update([
            'branch_id' => $validated['branch_id'],
            'supplier_id' => $validated['supplier_id'],
            'purchase_date' => $validated['purchase_date'],
            'invoice_number' => $validated['invoice_number'],
            'reference_number' => $validated['reference_number'],
            'status' => $status,
            'payment_status' => ($total - $paid) <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            'total_amount' => $total,
            'paid_amount' => $paid,
            'balance_amount' => max(0, $total - $paid),
            'notes' => $validated['notes'],
        ]);

        $this->purchase->items()->delete();

        foreach ($validated['items'] as $item) {
            $quantity = (float) $item['ordered_quantity'];
            $cost = (float) $item['cost_price'];
            $this->purchase->items()->create([
                'product_id' => $item['product_id'],
                'ordered_quantity' => $quantity,
                'received_quantity' => 0,
                'cost_price' => $cost,
                'selling_price' => $item['selling_price'] ?: null,
                'line_total' => $quantity * $cost,
            ]);
        }
    });

    session()->flash('success', 'Purchase updated successfully.');
    $this->redirectRoute('purchases.index', navigate: true);
};

?>

<div>
    <x-page-header title="Edit Purchase" description="Only purchases with no received stock can be edited." :breadcrumbs="['Dashboard' => route('dashboard'), 'Purchases' => route('purchases.index'), 'Edit' => null]" />

    @include('livewire.purchases.partials.form-fields')
</div>
