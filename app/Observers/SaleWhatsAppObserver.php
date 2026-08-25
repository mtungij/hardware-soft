<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Sale;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SaleWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Sale $sale): void
    {
        if (! $sale->wasChanged('status') || $sale->status !== 'cancelled') {
            return;
        }

        $company = Company::query()->find($sale->company_id);
        if (! $company) {
            return;
        }

        app(WhatsAppNotificationService::class)->queueForRecipients(
            $company, 'security', 'sale_cancelled', "sale:{$sale->id}:cancelled",
            app(WhatsAppMessageFactory::class)->saleCancelled($sale), (int) $sale->branch_id,
            metadata: ['sale_id' => $sale->id, 'sale_number' => $sale->sale_number],
        );
    }
}
