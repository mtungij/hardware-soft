<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->branch = Branch::findOrFail($this->admin->branch_id);
    $this->inventory = app(InventoryService::class);
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
    $this->product->fill(['name' => 'Transfer Cost Cement', 'sku' => 'TRANSFER-CANCEL-CEMENT', 'barcode' => null, 'buying_price' => 9999]);
    $this->product->save();
    $locations = collect(['Main Store Cost Test', 'Zanzibar store'])->map(fn ($name) => StockLocation::create([
        'company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id,
        'name' => $name, 'code' => str()->random(12), 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'is_warehouse' => false, 'is_dispensing_location' => false,
        'can_receive_stock' => true, 'can_issue_stock' => true, 'can_transfer' => true,
    ]));
    [$this->source, $this->destination] = $locations->all();
    cancelTransferMovement($this, $this->product, $this->source, 100, 4000);
    $this->transfer = cancelTransferDraft($this, [[$this->product, 2.5]]);
    $this->inventory->completeStockTransfer($this->transfer->id, $this->admin->id);
});

function cancelTransferMovement(object $test, Product $product, StockLocation $location, float $quantity, ?float $cost, string $type = 'purchase_receipt'): StockMovement
{
    return StockMovement::create([
        'company_id' => $test->admin->company_id, 'branch_id' => $test->branch->id,
        'product_id' => $product->id, 'stock_location_id' => $location->id,
        'movement_type' => $type, 'quantity' => $quantity,
        'unit_cost' => $cost, 'created_by' => $test->admin->id, 'movement_date' => today(),
    ]);
}

function cancelTransferDraft(object $test, array $lines): StockTransfer
{
    $transfer = StockTransfer::create([
        'company_id' => $test->admin->company_id, 'branch_id' => $test->branch->id,
        'transfer_number' => 'COST-'.str()->random(12), 'from_location_id' => $test->source->id,
        'to_location_id' => $test->destination->id, 'transfer_date' => today(),
        'status' => 'draft', 'created_by' => $test->admin->id,
    ]);
    foreach ($lines as [$product, $quantity]) {
        $transfer->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
    }

    return $transfer;
}

function cancelTransferPostedRows(StockTransfer $transfer)
{
    return StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $transfer->id)->orderBy('id')->get();
}

test('admin cancellation restores fractional stock and preserves the original audit records', function () {
    $this->admin->syncRoles(['Admin']);
    $originals = cancelTransferPostedRows($this->transfer);
    $before = $originals->toJson();
    $completedAt = $this->transfer->fresh()->completed_at->toISOString();
    $cancelled = $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, '  Wrong destination  ');
    $reversals = cancelTransferPostedRows($this->transfer)->whereIn('movement_type', ['transfer_cancel_out', 'transfer_cancel_in'])->values();
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancellation_reason)->toBe('Wrong destination')
        ->and($cancelled->cancelled_by)->toBe($this->admin->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->completed_at->toISOString())->toBe($completedAt)
        ->and(StockMovement::whereIn('id', $originals->modelKeys())->orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->inventory->getProductStock($this->product->id, $this->source->id, $this->branch->id))->toEqual(100)
        ->and($this->inventory->getProductStock($this->product->id, $this->destination->id, $this->branch->id))->toEqual(0)
        ->and($this->inventory->getProductTotalStock($this->product->id, $this->branch->id))->toEqual(100)
        ->and($reversals)->toHaveCount(2)
        ->and($reversals->pluck('unit_cost')->all())->toBe(['4000.000000', '4000.000000'])
        ->and($reversals->pluck('original_movement_id')->sort()->values()->all())->toBe($originals->modelKeys())
        ->and($reversals->pluck('stock_transfer_id')->unique()->all())->toBe([$this->transfer->id])
        ->and($reversals->pluck('stock_transfer_item_id')->unique()->all())->toBe([$this->transfer->items()->first()->id])
        ->and($reversals->pluck('company_id')->unique()->all())->toBe([$this->admin->company_id])
        ->and($reversals->pluck('created_by')->unique()->all())->toBe([$this->admin->id])
        ->and($reversals->pluck('notes')->unique()->all())->toBe(['Wrong destination'])
        ->and($reversals->map->signedQuantity()->all())->toBe([-2.5, 2.5]);
    $this->withSession(['staff_locale' => 'en'])->get(route('stock-transfers.show', $this->transfer))
        ->assertOk()->assertSee('Cancelled By')->assertSee('Cancelled Date')->assertSee('Wrong destination')
        ->assertSee('transfer_cancel_out')->assertSee('transfer_cancel_in')->assertDontSee('wire:click="openCancellation"', false);
});

test('unauthorized manager cannot invoke cancellation through service or livewire', function () {
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole('Manager');
    Role::findByName('Manager')->revokePermissionTo('cancel stock transfers');
    $this->actingAs($user);
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $user->id, 'No permission'))
        ->toThrow(HttpException::class);
    Volt::test('stock-transfers.show', ['stockTransfer' => $this->transfer])
        ->assertDontSee('wire:click="openCancellation"', false)
        ->call('cancelTransfer')->assertForbidden();
    expect($this->transfer->fresh()->status)->toBe('completed')
        ->and(StockMovement::orderBy('id')->get()->toJson())->toBe($before);
});

test('dedicated permission authorizes a scoped non-admin user', function () {
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole('Manager');
    $user->givePermissionTo('cancel stock transfers');
    $this->actingAs($user);
    expect($this->inventory->cancelStockTransfer($this->transfer->id, $user->id, 'Approved correction')->status)->toBe('cancelled');
});

test('repeated requests cannot reverse a transfer twice', function () {
    $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'First request');
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'Second request'))->toThrow(ValidationException::class);
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->transfer->fresh()->cancellation_reason)->toBe('First request');
});

test('insufficient destination stock rejects every item without partial changes', function () {
    $second = $this->product->replicate()->fill(['name' => 'MISUMALI', 'sku' => 'CANCEL-SECOND']);
    $second->save();
    cancelTransferMovement($this, $second, $this->source, 10, 100);
    $transfer = cancelTransferDraft($this, [[$this->product, 1], [$second, 2]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    cancelTransferMovement($this, $second, $this->destination, 1.5, 100, 'sale_out');
    $before = StockMovement::orderBy('id')->get()->toJson();
    try {
        $this->inventory->cancelStockTransfer($transfer->id, $this->admin->id, 'Cannot return sold stock');
        $this->fail('Insufficient stock must reject cancellation.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['transfer'][0])->toContain('MISUMALI', 'Available:', '0.5');
    }
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before)
        ->and($transfer->fresh()->status)->toBe('completed')
        ->and($transfer->fresh()->cancelled_at)->toBeNull();
});

test('duplicate product lines are checked together', function () {
    $transfer = cancelTransferDraft($this, [[$this->product, 2], [$this->product, 2]]);
    $this->inventory->completeStockTransfer($transfer->id, $this->admin->id);
    // Leave 3 units: enough for either line individually but not both.
    cancelTransferMovement($this, $this->product, $this->destination, 3.5, 4000, 'sale_out');
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($transfer->id, $this->admin->id, 'Duplicate lines'))->toThrow(ValidationException::class);
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before);
});

test('cross-company cancellation is rejected even for super admin', function () {
    $other = Company::create(['company_name' => 'Other Company', 'business_type' => 'hardware', 'phone' => '123', 'whatsapp_number' => '123']);
    DB::table('stock_transfers')->where('id', $this->transfer->id)->update(['company_id' => $other->id]);
    $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'Other tenant'))
        ->toThrow(ModelNotFoundException::class);
    expect(DB::table('stock_movements')->orderBy('id')->get()->toJson())->toBe($before);
});

test('reason and explicit confirmation are required by livewire', function () {
    $component = Volt::test('stock-transfers.show', ['stockTransfer' => $this->transfer]);
    $component->call('openCancellation')->assertDispatched('open-modal')
        ->call('cancelTransfer')->assertHasErrors(['cancellationReason', 'confirmCancellation'])
        ->set('cancellationReason', 'Wrong destination')->call('cancelTransfer')->assertHasErrors('confirmCancellation')
        ->set('confirmCancellation', true)->call('cancelTransfer')->assertHasNoErrors()->assertDispatched('close-modal');
    expect($this->transfer->fresh()->status)->toBe('cancelled');
});

test('blank reason and non-completed transfers are rejected by the service', function () {
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, '   '))->toThrow(ValidationException::class);
    $draft = cancelTransferDraft($this, [[$this->product, 1]]);
    expect(fn () => $this->inventory->cancelStockTransfer($draft->id, $this->admin->id, 'Draft'))->toThrow(ValidationException::class);
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before);
});

test('legacy movements without item links can be reversed without rewriting originals', function () {
    $originals = cancelTransferPostedRows($this->transfer);
    DB::table('stock_movements')->whereIn('id', $originals->modelKeys())->update(['stock_transfer_id' => null, 'stock_transfer_item_id' => null]);
    $before = cancelTransferPostedRows($this->transfer)->toJson();
    $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'Legacy correction');
    expect(StockMovement::whereIn('id', $originals->modelKeys())->orderBy('id')->get()->toJson())->toBe($before);
});

test('inconsistent original movements roll back reversals already inserted', function () {
    $out = cancelTransferPostedRows($this->transfer)->firstWhere('movement_type', 'transfer_out');
    DB::table('stock_movements')->where('id', $out->id)->update(['quantity' => 99]);
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'Broken history'))->toThrow(ValidationException::class);
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->transfer->fresh()->status)->toBe('completed');
});

test('cancellation permission cannot bypass assigned location scope', function () {
    $user = User::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $user->assignRole('Manager');
    Role::findByName('Manager')->forceFill(['stock_scope' => 'assigned_locations'])->save();
    $this->actingAs($user);
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $user->id, 'No assigned access'))->toThrow(HttpException::class);
    expect($this->transfer->fresh()->status)->toBe('completed');
});

test('cross-company items cannot be silently omitted from cancellation', function () {
    $other = Company::create(['company_name' => 'Other Company', 'business_type' => 'hardware', 'phone' => '123', 'whatsapp_number' => '123']);
    DB::table('stock_transfer_items')->where('stock_transfer_id', $this->transfer->id)->update(['company_id' => $other->id]);
    $before = StockMovement::orderBy('id')->get()->toJson();
    expect(fn () => $this->inventory->cancelStockTransfer($this->transfer->id, $this->admin->id, 'Invalid item tenant'))->toThrow(HttpException::class);
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->transfer->fresh()->status)->toBe('completed');
});

test('list draft cancellation cannot bypass completed transfer reversal', function () {
    $before = StockMovement::orderBy('id')->get()->toJson();
    Volt::test('stock-transfers.index')->call('cancelTransfer', $this->transfer->id)->assertHasErrors('transfer');
    expect(StockMovement::orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->transfer->fresh()->status)->toBe('completed');
});
