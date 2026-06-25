<?php

namespace App\Services;

use App\Jobs\SendPurchaseOrderJob;
use App\Mail\SupplierPurchaseOrderMail;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Throwable;

class PurchaseOrderEmailService
{
    public function validateCanSend(Purchase $purchase): string
    {
        $purchase->loadMissing('supplier');

        if (! in_array($purchase->status, ['draft', 'ordered'], true)) {
            throw ValidationException::withMessages(['purchase' => 'Only draft or ordered purchase orders can be emailed.']);
        }

        $email = $purchase->supplier?->email;

        if (blank($email) || Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            throw ValidationException::withMessages(['supplier' => 'Supplier email is empty or invalid.']);
        }

        return $email;
    }

    public function queue(Purchase $purchase, int $sentBy): PurchaseEmailLog
    {
        $recipient = $this->validateCanSend($purchase);
        $subject = "Purchase Order {$purchase->reference_number} - ".($this->settings()?->company_name ?? config('app.name'));

        $log = PurchaseEmailLog::create([
            'purchase_id' => $purchase->id,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'status' => 'pending',
            'sent_by' => $sentBy,
        ]);

        $purchase->update([
            'email_status' => 'pending',
            'email_recipient' => $recipient,
            'email_sent_by' => $sentBy,
        ]);

        SendPurchaseOrderJob::dispatch($purchase->id, $log->id);

        return $log;
    }

    public function send(Purchase $purchase, int $sentBy): PurchaseEmailLog
    {
        $recipient = $this->validateCanSend($purchase);
        $settings = $this->settings();
        $subject = "Purchase Order {$purchase->reference_number} - ".($settings?->company_name ?? config('app.name'));

        $log = PurchaseEmailLog::create([
            'purchase_id' => $purchase->id,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'status' => 'pending',
            'sent_by' => $sentBy,
        ]);

        $purchase->update([
            'email_status' => 'pending',
            'email_recipient' => $recipient,
            'email_sent_by' => $sentBy,
        ]);

        try {
            $this->configureMailer($settings);

            Mail::mailer('buildmart_smtp')
                ->to($recipient)
                ->send(new SupplierPurchaseOrderMail($purchase, $settings, $this->pdfBinary($purchase)));

            $log->update([
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => now(),
            ]);

            $purchase->update([
                'email_status' => 'sent',
                'email_recipient' => $recipient,
                'email_sent_at' => now(),
                'email_sent_by' => $sentBy,
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'sent_at' => now(),
            ]);

            $purchase->update([
                'email_status' => 'failed',
                'email_recipient' => $recipient,
                'email_sent_by' => $sentBy,
            ]);

            throw $exception;
        }

        return $log->refresh();
    }

    public function pdfBinary(Purchase $purchase): string
    {
        $purchase->loadMissing(['supplier', 'branch', 'items.product.unit']);
        $settings = $this->settings();
        $html = view('pdf.purchase-order', compact('purchase', 'settings'))->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 12,
            'margin_right' => 12,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function settings(): ?Setting
    {
        return Setting::query()->first();
    }

    public function configureMailer(?Setting $settings = null): void
    {
        $settings ??= $this->settings();

        if (! $settings?->mail_host) {
            return;
        }

        Config::set('mail.mailers.buildmart_smtp', [
            'transport' => 'smtp',
            'scheme' => $settings->mail_encryption === 'ssl' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => trim((string) $settings->mail_host),
            'port' => $settings->mail_port ?: 587,
            'username' => filled($settings->mail_username) ? trim((string) $settings->mail_username) : null,
            'password' => filled($settings->mail_password) ? trim((string) $settings->mail_password) : null,
            'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);

        Config::set('mail.from.address', filled($settings->mail_from_email) ? trim((string) $settings->mail_from_email) : config('mail.from.address'));
        Config::set('mail.from.name', filled($settings->mail_from_name) ? trim((string) $settings->mail_from_name) : ($settings->company_name ?: config('app.name')));
        Mail::forgetMailers();
    }
}
