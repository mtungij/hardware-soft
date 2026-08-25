<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\ProductionOrder;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ProductionOrderWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(ProductionOrder $order): void
    {
        if (! $order->wasChanged('status') || $order->status !== ProductionOrder::STATUS_COMPLETED) {
            return;
        }

        $company = Company::query()->find($order->company_id);
        if (! $company) {
            return;
        }

        app(WhatsAppNotificationService::class)->queueForRecipients(
            $company, 'production', 'production_completed', "production:{$order->id}:completed",
            app(WhatsAppMessageFactory::class)->productionCompleted($order), (int) $order->branch_id,
            metadata: ['production_order_id' => $order->id],
        );
    }
}
