<?php

use App\Models\CompanyWhatsAppSetting;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppNotification;
use App\Models\WhatsAppRecipient;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with(config('database.connections.sqlite.database'), sys_get_temp_dir().'/hardex-outbox-')) {
    throw new RuntimeException('Browser fixtures require an isolated temporary test database.');
}

if (($argv[1] ?? '') === 'check') {
    return;
}

if (($argv[1] ?? '') === 'inspect') {
    echo json_encode([
        'purchases' => Purchase::withoutGlobalScopes()->count(),
        'deleted' => Product::withoutGlobalScopes()->where('sku', 'BROWSER-OUTBOX')->firstOrFail()->trashed(),
        'notifications' => WhatsAppNotification::withoutGlobalScopes()->get(['company_id', 'branch_id', 'recipient_id', 'notification_type']),
    ]);

    return;
}

$admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
auth()->login($admin);
$recipient = WhatsAppRecipient::create(['company_id' => $admin->company_id, 'name' => 'Browser Manager',
    'phone' => '255764123456', 'scope' => 'company', 'active' => true,
    'categories' => ['purchase_order_created', 'security']]);
CompanyWhatsAppSetting::updateOrCreate(['company_id' => $admin->company_id], [
    'enabled' => true, 'sending_paused' => false, 'device_id' => 'browser-test-device',
    'enabled_categories' => ['purchase_order_created', 'security'],
]);
$product = Product::where('sku', 'BM-CEM-050')->firstOrFail()->replicate();
$product->fill(['name' => 'Browser Outbox Product', 'sku' => 'BROWSER-OUTBOX', 'barcode' => null])->save();
$supplier = Supplier::create(['name' => 'Browser Outbox Supplier', 'phone' => '255765123456',
    'branch_id' => $admin->branch_id, 'status' => 'active']);
$admin->update(['is_system_owner' => false]);
echo json_encode(['product' => $product->id, 'supplier' => $supplier->id,
    'recipient' => $recipient->id, 'company' => $admin->company_id, 'branch' => $admin->branch_id]);
