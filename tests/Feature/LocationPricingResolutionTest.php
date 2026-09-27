<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\LocationPriceService;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Location Price Cement', 'sku' => 'LOC-PRICE-CEMENT', 'barcode' => null, 'buying_price' => 4000, 'selling_price' => 7000, 'wholesale_price' => 6500]);
    $this->product->save();
    $this->location = StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Price Test Store', 'code' => 'PRICE-'.str()->random(8), 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_receive_stock' => true,
        'can_issue_stock' => true, 'can_sell' => true, 'is_sellable' => true,
    ]);
    Setting::query()->firstOrFail()->update([
        'enable_warehouse' => true, 'inventory_mode' => 'multi_location',
        'allow_sales_from_store' => true, 'default_stock_location_id' => $this->location->id,
    ]);
    StockMovement::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_location_id' => $this->location->id,
        'movement_type' => 'purchase_receipt', 'quantity' => 10, 'unit_cost' => 4000,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
});

test('location prices resolve for POS and internal price remains explicit', function () {
    $prices = app(LocationPriceService::class);
    expect($prices->priceFor($this->product, $this->location, 'retail'))->toEqual(7000)
        ->and($prices->priceFor($this->product, $this->location, 'wholesale'))->toEqual(6500)
        ->and($prices->priceFor($this->product, $this->location, 'internal'))->toEqual(7000);
    $prices->savePrices($this->product, $this->location, null, [
        'retail_price' => 8000, 'wholesale_price' => 7500, 'internal_sale_price' => 7800,
    ]);
    expect($prices->priceFor($this->product, $this->location, 'retail'))->toEqual(8000)
        ->and($prices->priceFor($this->product, $this->location, 'wholesale'))->toEqual(7500)
        ->and($prices->priceFor($this->product, $this->location, 'internal'))->toEqual(7800);
    $sale = app(InventoryService::class)->completeSale(
        [[
            'product_id' => $this->product->id, 'stock_location_id' => $this->location->id,
            'sale_type' => 'retail', 'quantity' => 2, 'unit_price' => 7000,
            'discount_amount' => 0, 'tax_amount' => 0,
        ]],
        [['payment_method' => 'cash', 'amount' => 16000, 'reference_number' => 'LOCATION-PRICE']],
        null, $this->location->id, $this->branch->id, $this->admin->id,
    );
    expect((float) $sale->items->first()->unit_price)->toBe(8000.0)
        ->and((float) $sale->total_amount)->toBe(16000.0);
    $other = StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => 'Other Price Store', 'code' => 'OTHER-'.str()->random(8), 'type' => 'store',
        'status' => 'active', 'is_active' => true,
    ]);
    expect($prices->priceFor($this->product, $other, 'retail'))->toEqual(7000)
        ->and($prices->priceFor($this->product, $other, 'internal'))->toEqual(7000);
});
