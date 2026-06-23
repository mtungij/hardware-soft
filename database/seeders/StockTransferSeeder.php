<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class StockTransferSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::query()->where('code', 'MAIN')->first();
        $admin = User::query()->where('email', 'admin@buildmart.test')->first();

        if (! $branch || ! $admin) {
            return;
        }

        $inventory = app(InventoryService::class);
        $from = $inventory->getMainStoreLocation($branch->id);
        $to = $inventory->getDispensingLocation($branch->id);

        if (StockTransfer::query()->where('transfer_number', 'TRF-SEED-0001')->exists()) {
            return;
        }

        $transfer = StockTransfer::create([
            'branch_id' => $branch->id,
            'transfer_number' => 'TRF-SEED-0001',
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'transfer_date' => now()->toDateString(),
            'status' => 'draft',
            'notes' => 'Seed transfer from Main Store to Dispensing Area.',
            'created_by' => $admin->id,
        ]);

        foreach (Product::query()->take(2)->get() as $product) {
            $available = $inventory->getProductStock($product->id, $from->id, $branch->id);
            $quantity = min(5, $available);

            if ($quantity <= 0) {
                continue;
            }

            $transfer->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'notes' => 'Seed transfer item',
            ]);
        }

        if ($transfer->items()->exists()) {
            $inventory->completeStockTransfer($transfer->id, $admin->id);
        } else {
            $transfer->delete();
        }
    }
}
