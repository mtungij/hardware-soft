<?php

use App\Jobs\SendPurchaseOrderJob;
use App\Mail\SupplierPurchaseOrderMail;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderEmailService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('purchase email pages and pdf render for super admin', function () {
    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::firstOrFail();

    $this->actingAs($admin)->get('/email-settings')->assertOk()->assertSee('Email Settings');
    $this->actingAs($admin)->get('/purchase-email-logs')->assertOk()->assertSee('Purchase Email Report');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}")->assertOk()->assertSee('Email Status')->assertSee('Download PDF');
    $this->actingAs($admin)->get("/purchases/{$purchase->id}/purchase-order-pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('queueing purchase order email creates pending log and updates purchase', function () {
    Queue::fake();

    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::with('supplier')->firstOrFail();

    app(PurchaseOrderEmailService::class)->queue($purchase, $admin->id);

    Queue::assertPushed(SendPurchaseOrderJob::class);
    expect(PurchaseEmailLog::where('purchase_id', $purchase->id)->where('status', 'pending')->exists())->toBeTrue();
    expect($purchase->fresh()->email_status)->toBe('pending');
    expect($purchase->fresh()->email_recipient)->toBe($purchase->supplier->email);
});

test('purchase order email is blocked when supplier email is invalid', function () {
    $supplier = Supplier::firstOrFail();
    $supplier->update(['email' => null]);
    $purchase = Purchase::where('supplier_id', $supplier->id)->firstOrFail();

    app(PurchaseOrderEmailService::class)->queue($purchase, User::where('email', 'admin@buildmart.test')->firstOrFail()->id);
})->throws(ValidationException::class);

test('send purchase order job sends mailable and marks log sent', function () {
    Mail::fake();

    $admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $purchase = Purchase::with('supplier')->firstOrFail();
    $log = PurchaseEmailLog::create([
        'purchase_id' => $purchase->id,
        'recipient_email' => $purchase->supplier->email,
        'subject' => 'Test PO',
        'status' => 'pending',
        'sent_by' => $admin->id,
    ]);

    (new SendPurchaseOrderJob($purchase->id, $log->id))->handle(app(PurchaseOrderEmailService::class));

    Mail::assertSent(SupplierPurchaseOrderMail::class);
    expect($log->fresh()->status)->toBe('sent');
    expect($purchase->fresh()->email_status)->toBe('sent');
    expect($purchase->fresh()->email_sent_at)->not->toBeNull();
});

test('store keeper can send purchase emails but cannot manage email settings', function () {
    $storeKeeper = User::factory()->create(['status' => 'active']);
    $storeKeeper->assignRole('Store Keeper');
    $purchase = Purchase::firstOrFail();

    $this->actingAs($storeKeeper)->get('/purchases')->assertOk()->assertSee('Send PO');
    $this->actingAs($storeKeeper)->get("/purchases/{$purchase->id}/purchase-order-pdf")->assertOk();
    $this->actingAs($storeKeeper)->get('/email-settings')->assertForbidden();
});
