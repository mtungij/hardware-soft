<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\AuthorizationScope;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->superAdmin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->branch = Branch::findOrFail($this->superAdmin->branch_id);
    $this->companyId = (int) $this->superAdmin->company_id;
    Setting::query()->firstOrFail()->update(['enable_warehouse' => true]);
    $this->actingAs($this->superAdmin);
});

function transferOptionLocation(object $test, string $name, array $overrides = []): StockLocation
{
    return StockLocation::create(array_merge([
        'company_id' => $test->companyId,
        'branch_id' => $test->branch->id,
        'name' => $name,
        'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
        'type' => 'store',
        'status' => 'active',
        'is_active' => true,
        'can_receive_stock' => true,
        'can_issue_stock' => true,
        'can_transfer' => true,
        'can_transfer_to_dispensing' => true,
    ], $overrides));
}

function assignTransferLocation(User $user, StockLocation $location, array $overrides = []): void
{
    $user->stockLocations()->attach($location->id, array_merge([
        'company_id' => $user->company_id,
        'branch_id' => $location->branch_id,
        'can_view' => true,
        'can_sell' => false,
        'can_transfer' => true,
        'can_receive' => true,
        'can_adjust' => false,
        'is_default' => false,
    ], $overrides));
}

test('admin and super admin see authorized branches and transfer capable locations including company wide locations', function () {
    $admin = User::factory()->create([
        'company_id' => $this->companyId,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);
    $admin->assignRole('Admin');

    $source = transferOptionLocation($this, 'WAREHOUSE OPTION');
    $destination = transferOptionLocation($this, 'SHOP OPTION');
    $companyWide = transferOptionLocation($this, 'ALL BRANCHES OPTION', ['branch_id' => null]);
    transferOptionLocation($this, 'INACTIVE OPTION', ['status' => 'inactive', 'is_active' => false]);
    transferOptionLocation($this, 'NO TRANSFER OPTION', ['can_transfer' => false]);
    $locationCount = StockLocation::count();

    foreach ([$admin, $this->superAdmin] as $user) {
        $this->actingAs($user);

        $component = Volt::test('stock-transfers.create');
        $sourceIds = $component->instance()->transferSourceLocations()->pluck('id');
        $destinationIds = $component->instance()->transferDestinationLocations()->pluck('id');

        expect($component->instance()->authorizedBranches()->pluck('id'))->toContain($this->branch->id)
            ->and($sourceIds)->toContain($source->id, $destination->id, $companyWide->id)
            ->and($sourceIds)->not->toContain(StockLocation::where('name', 'INACTIVE OPTION')->value('id'))
            ->and($sourceIds)->not->toContain(StockLocation::where('name', 'NO TRANSFER OPTION')->value('id'))
            ->and($destinationIds)->not->toContain((int) $component->get('from_location_id'));
    }

    expect(StockLocation::count())->toBe($locationCount);
});

test('restricted users only see directly authorized source and destination locations', function () {
    $user = User::factory()->create([
        'company_id' => $this->companyId,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);
    $user->assignRole('Store Keeper');

    $source = transferOptionLocation($this, 'ASSIGNED SOURCE');
    $destination = transferOptionLocation($this, 'ASSIGNED DESTINATION');
    $unauthorized = transferOptionLocation($this, 'UNAUTHORIZED LOCATION');
    $companyWide = transferOptionLocation($this, 'ASSIGNED COMPANY WIDE', ['branch_id' => null]);
    assignTransferLocation($user, $source, ['can_receive' => false]);
    assignTransferLocation($user, $destination, ['can_transfer' => false]);
    assignTransferLocation($user, $companyWide);

    $this->actingAs($user);
    $component = Volt::test('stock-transfers.create');

    expect($component->instance()->transferSourceLocations()->pluck('id'))
        ->toContain($source->id, $companyWide->id)
        ->not->toContain($destination->id, $unauthorized->id)
        ->and($component->instance()->transferDestinationLocations()->pluck('id'))
        ->toContain($destination->id)
        ->not->toContain($source->id, $unauthorized->id);
});

test('changing branch and source refreshes valid selections without a page reload', function () {
    $secondBranch = Branch::create([
        'company_id' => $this->companyId,
        'name' => 'Second Transfer Branch',
        'code' => 'TRF-BR-2',
        'status' => 'active',
    ]);
    $source = transferOptionLocation($this, 'SECOND SOURCE', ['branch_id' => $secondBranch->id, 'is_default' => true]);
    $destination = transferOptionLocation($this, 'SECOND DESTINATION', ['branch_id' => $secondBranch->id]);
    $alternate = transferOptionLocation($this, 'SECOND ALTERNATE', ['branch_id' => $secondBranch->id]);

    Volt::test('stock-transfers.create')
        ->set('items.0.product_id', (string) Product::firstOrFail()->id)
        ->set('branch_id', (string) $secondBranch->id)
        ->assertSet('from_location_id', (string) $source->id)
        ->assertSet('to_location_id', (string) $alternate->id)
        ->assertSet('items.0.product_id', '')
        ->set('from_location_id', (string) $destination->id)
        ->assertSet('to_location_id', (string) $alternate->id);
});

test('source availability is location specific and a completed transfer preserves company total stock', function () {
    $source = transferOptionLocation($this, 'LOCATION STOCK SOURCE');
    $destination = transferOptionLocation($this, 'LOCATION STOCK DESTINATION');
    $product = Product::firstOrFail();

    StockMovement::create([
        'company_id' => $this->companyId,
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $source->id,
        'movement_type' => 'adjustment_in',
        'quantity' => 84,
        'quantity_in' => 84,
        'quantity_out' => 0,
        'created_by' => $this->superAdmin->id,
        'movement_date' => today(),
    ]);

    $inventory = app(InventoryService::class);
    $companyBefore = StockMovement::where('product_id', $product->id)->get()->sum->signedQuantity();

    $component = Volt::test('stock-transfers.create')
        ->set('from_location_id', (string) $source->id)
        ->set('to_location_id', (string) $destination->id)
        ->set('items.0.product_id', (string) $product->id)
        ->set('items.0.quantity', '10');

    expect($component->instance()->availableQuantity((string) $product->id))->toBe(84.0);

    $component->call('saveTransfer', 'completed')->assertHasNoErrors();

    expect($inventory->getProductStock($product->id, $source->id, $this->branch->id))->toBe(74.0)
        ->and($inventory->getProductStock($product->id, $destination->id, $this->branch->id))->toBe(10.0)
        ->and(StockMovement::where('product_id', $product->id)->get()->sum->signedQuantity())->toBe($companyBefore)
        ->and(StockMovement::where('reference_type', StockTransfer::class)->where('movement_type', 'transfer_out')->count())->toBe(1)
        ->and(StockMovement::where('reference_type', StockTransfer::class)->where('movement_type', 'transfer_in')->count())->toBe(1);
});

test('transfer rejects quantity above exact source stock', function () {
    $source = transferOptionLocation($this, 'LIMITED SOURCE');
    $destination = transferOptionLocation($this, 'LIMITED DESTINATION');
    $product = Product::firstOrFail();

    StockMovement::create([
        'company_id' => $this->companyId,
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'stock_location_id' => $source->id,
        'movement_type' => 'adjustment_in',
        'quantity' => 5,
        'quantity_in' => 5,
        'quantity_out' => 0,
        'created_by' => $this->superAdmin->id,
        'movement_date' => today(),
    ]);

    Volt::test('stock-transfers.create')
        ->set('from_location_id', (string) $source->id)
        ->set('to_location_id', (string) $destination->id)
        ->set('items.0.product_id', (string) $product->id)
        ->set('items.0.quantity', '6')
        ->call('saveTransfer', 'completed')
        ->assertHasErrors(['items']);

    expect(AuthorizationScope::stockLocationsForBranch($this->superAdmin, 'can_transfer', $this->branch->id)->pluck('id'))->toContain($source->id)
        ->and(StockMovement::where('reference_type', StockTransfer::class)->count())->toBe(0);
});
