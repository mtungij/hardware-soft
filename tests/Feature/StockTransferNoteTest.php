<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockTransferNoteService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    Setting::firstOrFail()->update(['enable_warehouse' => true]);
    $this->source = StockLocation::create([
        'branch_id' => $this->admin->branch_id, 'name' => 'Zanzibar store', 'code' => 'ZS-NOTE', 'type' => 'store',
        'status' => 'active', 'is_active' => true, 'can_transfer' => true, 'can_issue_stock' => true,
        'can_transfer_to_dispensing' => true, 'can_receive_stock' => true,
    ]);
    $this->destination = $this->source->replicate()->fill(['name' => 'Dispensing Area Note', 'code' => 'DISP-NOTE', 'type' => 'dispensing']);
    $this->destination->save();
    $this->product = Product::where('sku', 'BM-CEM-050')->firstOrFail();
    StockMovement::create([
        'branch_id' => $this->admin->branch_id, 'product_id' => $this->product->id, 'stock_location_id' => $this->source->id,
        'movement_type' => 'purchase_receipt', 'quantity' => 100, 'unit_cost' => 4000,
        'created_by' => $this->admin->id, 'movement_date' => today(),
    ]);
    $this->transfer = StockTransfer::create([
        'branch_id' => $this->admin->branch_id, 'transfer_number' => 'TRF-20260909-0001',
        'from_location_id' => $this->source->id, 'to_location_id' => $this->destination->id,
        'transfer_date' => '2026-09-09', 'status' => 'draft', 'notes' => 'Counter replenishment', 'created_by' => $this->admin->id,
    ]);
    $this->transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 20]);
});

function completeNoteTransfer(object $test): void
{
    app(InventoryService::class)->completeStockTransfer($test->transfer->id, $test->admin->id);
}

test('completed dynamic location flows render print and real PDF without stock side effects', function ($fromType, $toType) {
    $this->source->update(['type' => $fromType]);
    $this->destination->update(['type' => $toType]);
    completeNoteTransfer($this);
    $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
    $header = $this->transfer->fresh()->toJson();
    $items = $this->transfer->items()->get()->toJson();
    $response = $this->get(route('stock-transfers.note.print', $this->transfer))->assertOk();
    foreach (['STOCK TRANSFER NOTE', 'TRF-20260909-0001', '09 Sep 2026', 'Zanzibar store', 'Dispensing Area Note',
        StockLocation::TYPES[$fromType], StockLocation::TYPES[$toType], $this->product->displayNameWithSize(), $this->product->sku,
        $this->admin->company->company_name, 'Counter replenishment', 'RELEASED BY', 'RECEIVED BY', '20'] as $text) {
        $response->assertSee($text);
    }
    $response->assertDontSee('unit_cost')->assertDontSee('Selling Price')->assertSee('window.print()', false);
    for ($i = 0; $i < 3; $i++) {
        $pdf = $this->get(route('stock-transfers.note.pdf', $this->transfer))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF-');
    }
    $this->get(route('stock-transfers.show', $this->transfer))->assertOk()->assertSee('Print Transfer Note');
    expect(DB::table('stock_movements')->orderBy('id')->get()->toJson())->toBe($before)
        ->and($this->transfer->fresh()->toJson())->toBe($header)
        ->and($this->transfer->items()->get()->toJson())->toBe($items);
    $posted = StockMovement::where('reference_type', StockTransfer::class)->where('reference_id', $this->transfer->id)->get();
    expect($posted)->toHaveCount(2)->and($posted->pluck('unit_cost')->all())->toBe(['4000.00', '4000.00']);
    expect(app(InventoryService::class)->getProductStock($this->product->id, $this->source->id, $this->admin->branch_id))->toEqual(80);
})->with([['store', 'store'], ['store', 'dispensing'], ['warehouse', 'store'], ['transit', 'dispensing']]);

test('draft and cancelled notes are unavailable', function ($status) {
    $this->transfer->update(['status' => $status]);
    foreach (['print', 'pdf'] as $format) {
        $this->get(route('stock-transfers.note.'.$format, $this->transfer))->assertStatus(409);
    }
    $this->get(route('stock-transfers.show', $this->transfer))->assertOk()->assertDontSee('Print Transfer Note');
})->with(['draft', 'cancelled']);

test('mixed base units and long product names render with separate totals', function () {
    $unit = Unit::create(['name' => 'Pieces Note', 'code' => 'NOTE-PCS', 'short_name' => 'note-pcs', 'status' => 'active']);
    $product = $this->product->replicate()->fill(['name' => str_repeat('Long reinforcement bar ', 12), 'sku' => 'NOTE-LONG', 'barcode' => null, 'unit_id' => $unit->id]);
    $product->save();
    $this->transfer->items()->create(['product_id' => $product->id, 'quantity' => 50]);
    // Historical completed records need no reconstruction or stock posting to render.
    $this->transfer->update(['status' => 'completed']);
    $this->get(route('stock-transfers.note.print', $this->transfer))->assertOk()
        ->assertSee($product->displayNameWithSize())->assertSee('Total Product Lines: 2')->assertSee('Total Quantity (note-pcs): 50')
        ->assertDontSee('Total Quantity: 70')->assertSee('word-wrap: break-word', false);
    $noteData = app(StockTransferNoteService::class)->data($this->transfer);
    $noteHtml = view('documents.stock-transfer-note', [...$noteData, 'isPdf' => true])->render();
    expect(strlen($noteHtml))->toBeGreaterThan(1000);
    expect($noteHtml)->toContain('STOCK TRANSFER NOTE', $this->transfer->transfer_number, 'Zanzibar store', 'Dispensing Area Note', $this->product->displayNameWithSize(), $product->displayNameWithSize(), '>20<', '>50<')
        ->not->toContain('@page');

    $notePdf = $this->get(route('stock-transfers.note.pdf', $this->transfer))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="stock-transfer-note-trf-20260909-0001.pdf"');
    $pdfBytes = $notePdf->getContent();
    expect($pdfBytes)->toStartWith('%PDF-')
        ->and(strlen($pdfBytes))->toBeGreaterThan(10000)
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes))->toBe(1);
});

test('unreadable company logo does not blank the transfer note PDF', function () {
    $this->transfer->update(['status' => 'completed']);
    $this->admin->company->update(['logo' => 'unreadable-logo.png']);
    Storage::shouldReceive('disk')->once()->with('public')->andThrow(new RuntimeException('Logo unavailable'));

    $response = $this->get(route('stock-transfers.note.pdf', $this->transfer))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-')
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()))->toBe(1);
});

test('company isolation protects all document and details routes', function () {
    completeNoteTransfer($this);
    $company = Company::create(['company_name' => 'Other Company', 'business_type' => 'hardware', 'phone' => '123', 'whatsapp_number' => '123']);
    DB::table('stock_transfers')->where('id', $this->transfer->id)->update(['company_id' => $company->id]);
    foreach (['stock-transfers.note.print', 'stock-transfers.note.pdf', 'stock-transfers.show'] as $route) {
        $this->get(route($route, $this->transfer))->assertNotFound();
    }
});

test('branch and assigned location visibility is enforced', function ($scope) {
    completeNoteTransfer($this);
    $role = Role::where('name', 'Store Keeper')->firstOrFail();
    $role->forceFill(['stock_scope' => $scope])->save();
    $this->admin->syncRoles([$role]);
    DB::table('user_stock_locations')->where('user_id', $this->admin->id)->delete();
    $this->admin->stockLocations()->attach($this->source->id, ['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'can_view' => true]);
    $this->admin->unsetRelation('roles');
    if ($scope === 'branch') {
        $this->admin->branch_id = null;
    }
    foreach (['stock-transfers.note.print', 'stock-transfers.note.pdf', 'stock-transfers.show'] as $route) {
        $this->get(route($route, $this->transfer))->assertForbidden();
    }
})->with(['branch', 'assigned_locations']);

test('users without stock transfer roles cannot read notes', function () {
    completeNoteTransfer($this);
    $this->admin->syncRoles([]);
    foreach (['print', 'pdf'] as $format) {
        $this->get(route('stock-transfers.note.'.$format, $this->transfer))->assertForbidden();
    }
});
