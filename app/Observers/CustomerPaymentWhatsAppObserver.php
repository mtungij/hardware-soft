<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\CustomerPayment;
use App\Services\WhatsAppMessageFactory;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CustomerPaymentWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function created(CustomerPayment $payment): void
    {
        $company = Company::query()->find($payment->company_id);
        if (! $company) {
            return;
        }

        app(WhatsAppNotificationService::class)->queueForRecipients(
            $company, 'customer_payments', 'customer_payment_received', "customer-payment:{$payment->id}:received",
            app(WhatsAppMessageFactory::class)->customerPayment($payment), (int) $payment->branch_id,
            metadata: ['customer_payment_id' => $payment->id],
        );
    }
}
