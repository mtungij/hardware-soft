<?php

namespace App\Mail;

use App\Models\Purchase;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupplierPurchaseOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Purchase $purchase,
        public ?Setting $settings,
        public string $pdfBinary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->settings?->mail_from_email
                ? new \Illuminate\Mail\Mailables\Address($this->settings->mail_from_email, $this->settings->mail_from_name ?: $this->settings->company_name)
                : null,
            subject: "Purchase Order {$this->purchase->reference_number} - ".($this->settings?->company_name ?? config('app.name')),
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
