<?php

use App\Models\GoodsReceivingNote;
use App\Models\Purchase;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('phase three pages render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::firstOrFail();

    $this->actingAs($admin)->get('/purchases')->assertOk()->assertSee('Purchases');
    $this->actingAs($admin)->get('/purchases/create')->assertOk()->assertSee('Create Purchase');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}")->assertOk()->assertSee('Purchase Details');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}/receive")->assertOk()->assertSee('Receive Purchase');
    $this->actingAs($admin)->get('/store-stock')->assertOk()->assertSee('Main Store Stock');
    $this->actingAs($admin)->get('/stock-movements')->assertOk()->assertSee('Stock Movements');
    $this->actingAs($admin)->get('/stock-adjustments')->assertOk()->assertSee('Stock Adjustments');
    $this->actingAs($admin)->get('/stock-adjustments/create')->assertOk()->assertSee('Create Stock Adjustment');
    $this->actingAs($admin)->get('/stock-adjustments/approve')->assertOk()->assertSee('Approve Stock Adjustments');
});

test('stock locations and sample purchase movements are seeded', function () {
    expect(StockLocation::where('code', 'MAIN-STORE')->where('type', 'store')->exists())->toBeTrue();
    expect(StockLocation::where('code', 'DISPENSING')->where('type', 'dispensing')->exists())->toBeTrue();
    expect(Purchase::where('reference_number', 'PO-SEED-0001')->exists())->toBeTrue();
    expect(StockMovement::where('movement_type', 'purchase_in')->exists())->toBeTrue();
});

test('receiving purchase creates grn and purchase in movements', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::where('status', 'ordered')->firstOrFail();
    $item = $purchase->items()->firstOrFail();
    $remaining = $item->remainingQuantity();

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => $remaining],
        now()->toDateString(),
        $admin->id,
        'Test receiving'
    );

    expect(GoodsReceivingNote::where('purchase_id', $purchase->id)->count())->toBeGreaterThan(0);
    expect(StockMovement::where('reference_type', GoodsReceivingNote::class)->where('movement_type', 'purchase_in')->count())->toBeGreaterThan(0);
    expect($item->refresh()->received_quantity)->toEqual($item->ordered_quantity);
});

test('receiving cannot exceed remaining quantity', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::where('status', 'ordered')->firstOrFail();
    $item = $purchase->items()->firstOrFail();

    app(InventoryService::class)->receivePurchase(
        $purchase,
        [$item->id => $item->remainingQuantity() + 1],
        now()->toDateString(),
        $admin->id
    );
})->throws(ValidationException::class);

test('cashier can view store stock only from phase three pages', function () {
    $cashier = User::factory()->create(['status' => 'active']);
    $cashier->assignRole('Cashier');

    $this->actingAs($cashier)->get('/store-stock')->assertOk();
    $this->actingAs($cashier)->get('/purchases')->assertForbidden();
    $this->actingAs($cashier)->get('/stock-movements')->assertForbidden();
    $this->actingAs($cashier)->get('/stock-adjustments')->assertForbidden();
});
