<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase four pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $transfer = StockTransfer::firstOrFail();

    $this->actingAs($admin)->get('/stock-transfers')->assertOk()->assertSee('Stock Transfers');
    $this->actingAs($admin)->get('/stock-transfers/create')->assertOk()->assertSee('Create Stock Transfer');
    $this->actingAs($admin)->get("/stock-transfers/{$transfer->id}")->assertOk()->assertSee('Stock Transfer Details');
    $this->actingAs($admin)->get('/dispensing-stock')->assertOk()->assertSee('Dispensing Stock');
    $this->actingAs($admin)->get('/inventory-summary')->assertOk()->assertSee('Inventory Summary');
});

test('seeded transfer creates transfer out and transfer in movements', function () {
    $transfer = StockTransfer::where('transfer_number', 'TRF-SEED-0001')->firstOrFail();

    expect($transfer->status)->toBe('completed');
    expect(StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $transfer->id)->where('movement_type', 'transfer_out')->count())->toBeGreaterThan(0);
    expect(StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $transfer->id)->where('movement_type', 'transfer_in')->count())->toBeGreaterThan(0);
});

test('completing transfer moves stock from store to dispensing', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $inventory = app(InventoryService::class);
    $store = $inventory->getMainStoreLocation($branch->id);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::query()->get()->first(fn ($product) => $inventory->getProductStock($product->id, $store->id, $branch->id) >= 1);

    expect($product)->not->toBeNull();

    $storeBefore = $inventory->getProductStock($product->id, $store->id, $branch->id);
    $dispensingBefore = $inventory->getProductStock($product->id, $dispensing->id, $branch->id);

    $transfer = StockTransfer::create([
        'branch_id' => $branch->id,
        'transfer_number' => 'TRF-TEST-0001',
        'from_location_id' => $store->id,
        'to_location_id' => $dispensing->id,
        'transfer_date' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $admin->id,
    ]);

    $transfer->items()->create([
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $inventory->completeStockTransfer($transfer->id, $admin->id);

    expect($inventory->getProductStock($product->id, $store->id, $branch->id))->toEqual($storeBefore - 1);
    expect($inventory->getProductStock($product->id, $dispensing->id, $branch->id))->toEqual($dispensingBefore + 1);
});

test('transfer cannot exceed main store stock', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $branch = Branch::where('code', 'MAIN')->firstOrFail();
    $inventory = app(InventoryService::class);
    $store = $inventory->getMainStoreLocation($branch->id);
    $dispensing = $inventory->getDispensingLocation($branch->id);
    $product = Product::firstOrFail();
    $available = $inventory->getProductStock($product->id, $store->id, $branch->id);

    $transfer = StockTransfer::create([
        'branch_id' => $branch->id,
        'transfer_number' => 'TRF-TEST-OVER',
        'from_location_id' => $store->id,
        'to_location_id' => $dispensing->id,
        'transfer_date' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $admin->id,
    ]);

    $transfer->items()->create([
        'product_id' => $product->id,
        'quantity' => $available + 1,
    ]);

    $inventory->completeStockTransfer($transfer->id, $admin->id);
})->throws(ValidationException::class);

test('cashier can view dispensing and summary but not transfers', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)->get('/dispensing-stock')->assertOk();
    $this->actingAs($cashier)->get('/inventory-summary')->assertOk();
    $this->actingAs($cashier)->get('/stock-transfers')->assertForbidden();
});
