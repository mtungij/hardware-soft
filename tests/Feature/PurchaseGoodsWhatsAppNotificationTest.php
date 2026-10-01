<?php

use App\Jobs\SendWhatsAppNotification;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCostType;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use App\Observers\PurchaseGoodsWhatsAppObserver;
use App\Services\InventoryService;
use App\Services\PurchaseCostBreakdownService;
use App\Services\WhatsAppDailySummaryService;
use App\Services\WhatsAppPurchaseNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();
    $this->company = Company::query()->firstOrFail();
    $this->branch = Branch::query()->where('company_id', $this->company->id)->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->location = app(InventoryService::class)->getMainStoreLocation($this->branch->id);
    $this->location->update(['can_receive_stock' => true, 'is_active' => true, 'status' => 'active']);
    CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $this->company->id], [
        'enabled' => true, 'sending_paused' => false, 'device_id' => 'purchase-test-device',
        'enabled_categories' => ['purchase_order_created', 'goods_received_grn'],
    ]);
});

function purchaseNotificationRecipient(Company $company, array $attributes = []): WhatsAppRecipient
{
    return WhatsAppRecipient::withoutGlobalScopes()->create(array_merge([
        'company_id' => $company->id, 'name' => 'Purchase Manager',
        'phone' => '255764123456', 'scope' => 'company', 'active' => true,
        'categories' => ['purchase_order_created', 'goods_received_grn'],
    ], $attributes));
}

function purchaseNotificationOrder(Company $company, Branch $branch, User $admin, int $quantity = 100): array
{
    $supplier = Supplier::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'Snapshot Supplier', 'phone' => '255765123456', 'status' => 'active',
    ]);
    $product = Product::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
    $purchase = Purchase::withoutEvents(fn () => Purchase::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
        'purchase_date' => today(), 'reference_number' => 'WA-PO-'.str()->random(8),
        'status' => 'ordered', 'payment_status' => 'unpaid', 'total_amount' => $quantity * 15000,
        'paid_amount' => 0, 'balance_amount' => $quantity * 15000, 'created_by' => $admin->id,
    ]));
    $item = $purchase->items()->create([
        'company_id' => $company->id, 'product_id' => $product->id,
        'purchase_unit_id' => $product->purchase_unit_id, 'stock_unit_id' => $product->unit_id,
        'purchase_conversion_factor' => $product->purchaseConversionFactor(),
        'ordered_quantity' => $quantity, 'received_quantity' => 0,
        'cost_price' => 15000, 'selling_price' => 18000, 'line_total' => $quantity * 15000,
    ]);

    return [$purchase, $item, $product];
}

test('PO observer waits for commit and queues actual ordered products with transaction costs once', function () {
    expect(app(PurchaseGoodsWhatsAppObserver::class))->toBeInstanceOf(ShouldHandleEventsAfterCommit::class);
    $recipient = purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    [$purchase, $item, $product] = purchaseNotificationOrder($this->company, $this->branch, $this->admin);
    $second = $product->replicate();
    $second->name = 'Second Ordered Product';
    $second->sku = 'WA-SECOND-'.str()->random(6);
    $second->save();
    $purchase->items()->create([
        'company_id' => $this->company->id, 'product_id' => $second->id,
        'purchase_unit_id' => $second->purchase_unit_id, 'stock_unit_id' => $second->unit_id,
        'purchase_conversion_factor' => $second->purchaseConversionFactor(),
        'ordered_quantity' => 2, 'received_quantity' => 0,
        'cost_price' => 8000, 'selling_price' => 10000, 'line_total' => 16000,
    ]);
    app(PurchaseGoodsWhatsAppObserver::class)->created($purchase);
    app(PurchaseGoodsWhatsAppObserver::class)->created($purchase);

    $log = WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $recipient->id)->sole();
    expect($log->notification_type)->toBe('purchase_order_created')
        ->and($log->message)->toContain($product->name, 'Second Ordered Product', '15,000', '16,000', $purchase->reference_number)
        ->and($log->metadata['po_number'])->toBe($purchase->reference_number)
        ->and($log->idempotency_key)->toContain('purchase_order_created:'.$purchase->id);
    Queue::assertPushed(SendWhatsAppNotification::class, 1);
    $product->update(['buying_price' => 999999]);
    expect($log->fresh()->message)->toContain('15,000')->not->toContain('999,999');
});

test('disabled PO category queues nothing', function () {
    purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->firstOrFail()->update(['enabled_categories' => ['goods_received_grn']]);
    [$purchase] = purchaseNotificationOrder($this->company, $this->branch, $this->admin);
    expect(app(WhatsAppPurchaseNotificationService::class)->queuePurchase($purchase))->toBe([])
        ->and(WhatsAppNotification::withoutGlobalScopes()->count())->toBe(0);
});

test('posted partial GRNs use only their own landed snapshots and hide costs from restricted recipients', function () {
    $manager = purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $cashier->givePermissionTo('purchases.view');
    $restricted = purchaseNotificationRecipient($this->company, [
        'name' => 'Store Clerk', 'phone' => '255764123457', 'user_id' => $cashier->id,
    ]);
    [$purchase, $item, $product] = purchaseNotificationOrder($this->company, $this->branch, $this->admin);
    app(PurchaseCostBreakdownService::class)->ensureDefaultTypes($this->company->id);
    $transport = PurchaseCostType::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->where('name', 'Transportation')->firstOrFail();
    $service = app(InventoryService::class);
    $first = $service->receivePurchase($purchase,
        [$item->id => ['quantity' => 60, 'stock_location_id' => $this->location->id]],
        today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [['type_id' => $transport->id, 'amount' => 60000, 'payee' => 'Haulier']]],
    );
    $second = $service->receivePurchase($purchase->fresh(),
        [$item->id => ['quantity' => 40, 'stock_location_id' => $this->location->id]],
        today()->toDateString(), $this->admin->id,
        header: ['additional_costs' => [['type_id' => $transport->id, 'amount' => 20000, 'payee' => 'Haulier']]],
    );
    $notifications = app(WhatsAppPurchaseNotificationService::class);
    $notifications->queueReceipt($first);
    $notifications->queueReceipt($second);
    $notifications->queueReceipt($second);

    $logs = WhatsAppNotification::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->where('notification_type', 'goods_received_grn')->get();
    expect($logs)->toHaveCount(4);
    $firstManager = $logs->first(fn ($log) => $log->recipient_id === $manager->id && $log->metadata['grn_number'] === $first->grn_number);
    $secondManager = $logs->first(fn ($log) => $log->recipient_id === $manager->id && $log->metadata['grn_number'] === $second->grn_number);
    $firstRestricted = $logs->first(fn ($log) => $log->recipient_id === $restricted->id && $log->metadata['grn_number'] === $first->grn_number);
    expect($firstManager->message)->toContain($product->name, $this->location->name, '60', '40', '15,000', '60,000', '16,000', '960,000')
        ->and($secondManager->message)->toContain('40', '60', '20,000', '15,500', '620,000')
        ->not->toContain('960,000')
        ->and($firstRestricted->message)->toContain($first->grn_number, $product->name, $this->location->name)
        ->not->toContain('15,000', '60,000', '16,000', '960,000', 'Landed', 'Goods Value');
    $product->update(['buying_price' => 999999]);
    expect($firstManager->fresh()->message)->toContain('16,000')->not->toContain('999,999');
    Queue::assertPushed(SendWhatsAppNotification::class, 4);
});

test('company and branch recipient scopes stay isolated for PO and GRN', function () {
    $companyRecipient = purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    $wrongBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'name' => 'Other Branch',
        'code' => 'WA-OTHER-'.str()->random(5), 'status' => 'active',
    ]);
    purchaseNotificationRecipient($this->company, ['name' => 'Other Branch', 'phone' => '255764123458',
        'scope' => 'branch', 'branch_id' => $wrongBranch->id]);
    $otherCompany = Company::query()->create([
        'company_name' => 'Another Notification Company', 'business_type' => 'hardware',
        'phone' => '255700200200', 'whatsapp_number' => '255700200200',
    ]);
    purchaseNotificationRecipient($otherCompany, ['phone' => '255764123459']);
    [$purchase, $item] = purchaseNotificationOrder($this->company, $this->branch, $this->admin);
    $service = app(WhatsAppPurchaseNotificationService::class);
    $service->queuePurchase($purchase);
    $receipt = app(InventoryService::class)->receivePurchase($purchase,
        [$item->id => ['quantity' => 100, 'stock_location_id' => $this->location->id]],
        today()->toDateString(), $this->admin->id);
    $service->queueReceipt($receipt);
    expect(WhatsAppNotification::withoutGlobalScopes()->count())->toBe(2)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $companyRecipient->id)->count())->toBe(2)
        ->and(WhatsAppNotification::withoutGlobalScopes()->where('company_id', $otherCompany->id)->count())->toBe(0);
});

test('daily summary includes PO and posted GRN counts and saved values without leaking costs', function () {
    $manager = purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    $cashier = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
    $cashier->assignRole('Cashier');
    $cashier->givePermissionTo('purchases.view');
    $restricted = purchaseNotificationRecipient($this->company, ['phone' => '255764123457', 'user_id' => $cashier->id]);
    [$purchase, $item] = purchaseNotificationOrder($this->company, $this->branch, $this->admin);
    $receipt = app(InventoryService::class)->receivePurchase($purchase,
        [$item->id => ['quantity' => 100, 'stock_location_id' => $this->location->id]],
        today()->toDateString(), $this->admin->id);
    $summary = app(WhatsAppDailySummaryService::class);
    $managerData = $summary->build($this->company, $manager, now());
    expect($managerData['purchase_orders_today']['count'])->toBe(1)
        ->and($managerData['purchase_orders_today']['value'])->toBe(1500000.0)
        ->and($managerData['goods_received_today']['count'])->toBe(1)
        ->and($managerData['goods_received_today']['landed_value'])->toBe(1500000.0)
        ->and($summary->message($managerData))->toContain($purchase->reference_number, $receipt->grn_number);
    $restrictedData = $summary->build($this->company, $restricted, now());
    expect(data_get($restrictedData, 'purchase_orders_today.value'))->toBeNull()
        ->and(data_get($restrictedData, 'goods_received_today.landed_value'))->toBeNull();
});

test('posted GRN lists every received product and disabled category suppresses delivery', function () {
    $manager = purchaseNotificationRecipient($this->company, ['user_id' => $this->admin->id]);
    [$purchase, $firstItem, $product] = purchaseNotificationOrder($this->company, $this->branch, $this->admin, 2);
    $other = $product->replicate();
    $other->name = 'Second Received Product';
    $other->sku = 'WA-RECEIVED-'.str()->random(6);
    $other->save();
    $secondItem = $purchase->items()->create([
        'company_id' => $this->company->id, 'product_id' => $other->id,
        'purchase_unit_id' => $other->purchase_unit_id, 'stock_unit_id' => $other->unit_id,
        'purchase_conversion_factor' => $other->purchaseConversionFactor(),
        'ordered_quantity' => 3, 'received_quantity' => 0,
        'cost_price' => 2000, 'selling_price' => 3000, 'line_total' => 6000,
    ]);
    $receipt = app(InventoryService::class)->receivePurchase($purchase,
        [
            $firstItem->id => ['quantity' => 2, 'stock_location_id' => $this->location->id],
            $secondItem->id => ['quantity' => 3, 'stock_location_id' => $this->location->id],
        ], today()->toDateString(), $this->admin->id);
    $notificationService = app(WhatsAppPurchaseNotificationService::class);
    $notificationService->queueReceipt($receipt);
    $log = WhatsAppNotification::withoutGlobalScopes()->where('recipient_id', $manager->id)->sole();
    expect($log->message)->toContain($product->name, 'Second Received Product', $this->location->name);
    CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->firstOrFail()->update(['enabled_categories' => ['purchase_order_created']]);
    expect($notificationService->queueReceipt($receipt))->toBe([])
        ->and(WhatsAppNotification::withoutGlobalScopes()->count())->toBe(1);
});
