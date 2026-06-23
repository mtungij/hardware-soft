<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class PurchaseSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();
        $supplier = Supplier::query()->first();
        $admin = User::query()->where('email', 'admin@buildmart.test')->first();

        if (! $branch || ! $supplier || ! $admin) {
            return;
        }

        $purchase = Purchase::query()->firstOrCreate(
            ['reference_number' => 'PO-SEED-0001'],
            [
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'purchase_date' => now()->toDateString(),
                'invoice_number' => 'INV-SEED-001',
                'status' => 'ordered',
                'payment_status' => 'partial',
                'total_amount' => 0,
                'paid_amount' => 1000000,
                'balance_amount' => 0,
                'notes' => 'Seed purchase for Phase 3 receiving.',
                'created_by' => $admin->id,
            ]
        );

        if ($purchase->items()->exists()) {
            return;
        }

        foreach (Product::query()->take(3)->get() as $product) {
            $quantity = 20;
            $costPrice = (float) $product->buying_price;

            $purchase->items()->create([
                'product_id' => $product->id,
                'ordered_quantity' => $quantity,
                'received_quantity' => 0,
                'cost_price' => $costPrice,
                'selling_price' => $product->selling_price,
                'line_total' => $quantity * $costPrice,
            ]);
        }

        $purchase->update([
            'total_amount' => $purchase->items()->sum('line_total'),
            'balance_amount' => max(0, $purchase->items()->sum('line_total') - 1000000),
        ]);

        $receivedQuantities = $purchase->items()->pluck('ordered_quantity', 'id')->map(fn ($quantity) => (float) $quantity / 2)->all();

        app(InventoryService::class)->receivePurchase(
            $purchase,
            $receivedQuantities,
            now()->toDateString(),
            $admin->id,
            'Seed partial receiving into Main Store.'
        );
    }
}
