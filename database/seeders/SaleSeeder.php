<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class SaleSeeder extends Seeder
{
    public function run(): void
    {
        if (Sale::query()->where('sale_number', 'SALE-SEED-0001')->exists()) {
            return;
        }

        $branch = Branch::query()->where('code', 'MAIN')->first();
        $admin = User::query()->where('email', 'admin@buildmart.test')->first();

        if (! $branch || ! $admin) {
            return;
        }

        $inventory = app(InventoryService::class);
        $dispensing = $inventory->getDispensingLocation($branch->id);
        $product = Product::query()
            ->get()
            ->first(fn (Product $product) => $inventory->getProductStock($product->id, $dispensing->id, $branch->id) >= 1);

        if (! $product) {
            return;
        }

        $sale = $inventory->completeSale(
            [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $product->selling_price,
                'discount_amount' => 0,
                'tax_amount' => $product->taxable ? round((float) $product->selling_price * 0.18, 2) : 0,
            ]],
            [[
                'payment_method' => 'cash',
                'amount' => $product->taxable ? round((float) $product->selling_price * 1.18, 2) : (float) $product->selling_price,
                'reference_number' => 'SEED-CASH',
            ]],
            null,
            $dispensing->id,
            $branch->id,
            $admin->id,
            'Seed POS sale from Dispensing Area.'
        );

        $sale->update(['sale_number' => 'SALE-SEED-0001']);
    }
}
