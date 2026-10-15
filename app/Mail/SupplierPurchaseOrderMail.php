<?php

namespace App\Mail;

use App\Models\Purchase;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierPurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Purchase $purchase,
        public ?Setting $settings,
        public string $pdfBinary,
    ) {
        $this->locale(app()->getLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settings?->mail_from_email
                ? new Address($this->settings->mail_from_email, $this->settings->mail_from_name ?: $this->settings->company_name)
                : null,
            subject: __('mail.purchase.subject', ['reference' => $this->purchase->reference_number, 'company' => $this->settings?->company_name ?? config('app.name')], $this->locale),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.purchase-order',
            with: [
                'purchase' => $this->purchase,
                'settings' => $this->settings,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, "purchase-order-{$this->purchase->reference_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
