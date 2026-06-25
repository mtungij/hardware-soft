<?php

namespace App\Jobs;

use App\Mail\SupplierPurchaseOrderMail;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPurchaseOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $purchaseId,
        public int $logId,
    ) {}

    public function handle(PurchaseOrderEmailService $service): void
    {
        $purchase = Purchase::with(['supplier', 'branch', 'items.product.unit'])->findOrFail($this->purchaseId);
        $log = PurchaseEmailLog::findOrFail($this->logId);
        $settings = $service->settings();

        $service->configureMailer($settings);

        try {
            $recipient = $service->validateCanSend($purchase);
            $pdf = $service->pdfBinary($purchase);

            Mail::mailer('buildmart_smtp')->to($recipient)->send(new SupplierPurchaseOrderMail($purchase, $settings, $pdf));

            $log->update([
                'recipient_email' => $recipient,
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => now(),
            ]);

            $purchase->update([
                'email_status' => 'sent',
                'email_recipient' => $recipient,
                'email_sent_at' => now(),
                'email_sent_by' => $log->sent_by,
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'sent_at' => now(),
            ]);

            $purchase->update([
                'email_status' => 'failed',
                'email_recipient' => $log->recipient_email,
                'email_sent_by' => $log->sent_by,
            ]);

            throw $exception;
        }
    }

}
