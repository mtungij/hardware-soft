<?php

namespace App\Observers;

use App\Models\GoodsReceivingNote;
use App\Models\Purchase;
use App\Services\WhatsAppPurchaseNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PurchaseGoodsWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function created(object $model): void
    {
        if ($model instanceof Purchase) {
            app(WhatsAppPurchaseNotificationService::class)->queuePurchase($model);
        }

        if ($model instanceof GoodsReceivingNote && $model->status === 'posted') {
            app(WhatsAppPurchaseNotificationService::class)->queueReceipt($model);
        }
    }

    public function updated(object $model): void
    {
        if ($model instanceof GoodsReceivingNote && $model->wasChanged('status') && $model->status === 'posted') {
            app(WhatsAppPurchaseNotificationService::class)->queueReceipt($model);
        }
    }
}
