<?php

// This fixture is only used by the isolated Chrome regression test.
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with(config('database.connections.sqlite.database'), sys_get_temp_dir().'/hardex-cancel-')) {
    throw new RuntimeException('Browser fixtures require an isolated temporary test database.');
}

$admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
$admin->update(['is_system_owner' => false]);
auth()->login($admin);
$app->setLocale('en');
$product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
$product->fill(['name' => 'Browser Cancellation Product', 'sku' => 'BROWSER-CANCEL', 'barcode' => null])->save();
$locations = collect(['Browser Source', 'Browser Destination'])->map(fn ($name) => StockLocation::create([
    'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
    'name' => $name, 'code' => str()->random(12), 'type' => 'store',
    'status' => 'active', 'is_active' => true, 'is_warehouse' => false, 'is_dispensing_location' => false,
    'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true,
]));
[$source, $destination] = $locations->all();
$movement = fn ($location, $quantity, $type) => StockMovement::create([
    'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
    'product_id' => $product->id, 'stock_location_id' => $location->id,
    'movement_type' => $type, 'quantity' => $quantity, 'unit_cost' => 4000,
    'created_by' => $admin->id, 'movement_date' => today(),
]);
$movement($source, 100, 'purchase_receipt');
$transfers = [];
foreach (['success', 'insufficient'] as $scenario) {
    $transfer = StockTransfer::create([
        'company_id' => $admin->company_id, 'branch_id' => $admin->branch_id,
        'transfer_number' => 'BROWSER-'.strtoupper($scenario), 'from_location_id' => $source->id,
        'to_location_id' => $destination->id, 'transfer_date' => today(),
        'status' => 'draft', 'created_by' => $admin->id,
    ]);
    $transfer->items()->create(['product_id' => $product->id, 'quantity' => 2.5]);
    app(InventoryService::class)->completeStockTransfer($transfer->id, $admin->id);
    $transfers[$scenario] = $transfer->id;
}
// Both transfers require 2.5 units, but only 2.5 remain. Cancelling the
// successful transfer first makes the second cancellation fail atomically.
$movement($destination, 2.5, 'sale_out');
echo json_encode($transfers);
