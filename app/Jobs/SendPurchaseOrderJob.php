<?php

namespace App\Jobs;

use App\Mail\SupplierPurchaseOrderMail;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Services\PurchaseOrderEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
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

        $this->configureMailer($settings);

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

    private function configureMailer($settings): void
    {
        if (! $settings?->mail_host) {
            return;
        }

        Config::set('mail.mailers.buildmart_smtp', [
            'transport' => 'smtp',
            'scheme' => $settings->mail_encryption === 'ssl' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => $settings->mail_host,
            'port' => $settings->mail_port ?: 587,
            'username' => $settings->mail_username,
            'password' => $settings->mail_password,
            'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
        Config::set('mail.from.address', $settings->mail_from_email ?: config('mail.from.address'));
        Config::set('mail.from.name', $settings->mail_from_name ?: $settings->company_name);
        Mail::forgetMailers();
    }
}
