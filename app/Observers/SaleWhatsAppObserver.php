<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Sale;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use App\Support\AuthorizationScope;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

class SaleWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Sale $sale): void
    {
        if ($sale->status === 'completed') {
            $this->queueCompleted($sale);
        }
    }

    public function updated(Sale $sale): void
    {
        if (! $sale->wasChanged('status')) {
            return;
        }

        if ($sale->status === 'completed') {
            $this->queueCompleted($sale);

            return;
        }

        if ($sale->status !== 'cancelled') {
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

    private function queueCompleted(Sale $sale): void
    {
        try {
            $company = Company::query()->find($sale->company_id);
            if (! $company) {
                return;
            }

            app(WhatsAppNotificationService::class)->queueForRecipients(
                $company,
                'sales',
                'sale_completed',
                "sale:{$sale->id}:completed",
                app(WhatsAppMessageFactory::class)->saleCompleted($sale),
                (int) $sale->branch_id,
                metadata: ['sale_id' => $sale->id, 'sale_number' => $sale->sale_number],
                recipientFilter: fn ($recipient): bool => ! $recipient->user_id
                    || AuthorizationScope::canAccessSale($recipient->user, $sale),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
