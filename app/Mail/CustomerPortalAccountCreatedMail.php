<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerPortalAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public CustomerAccount $account,
        public string $temporaryPassword,
        public string $portalUrl,
        public ?Company $company,
        public ?Setting $settings,
    ) {}

    public function envelope(): Envelope
    {
        $companyName = $this->company?->company_name ?: ($this->settings?->company_name ?: config('app.name'));

        return new Envelope(
            from: $this->settings?->mail_from_email
                ? new \Illuminate\Mail\Mailables\Address($this->settings->mail_from_email, $this->settings->mail_from_name ?: $companyName)
                : null,
            subject: "Karibu {$companyName} Customer Portal",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.customer-portal-account-created',
            with: [
                'customer' => $this->customer,
                'account' => $this->account,
                'temporaryPassword' => $this->temporaryPassword,
                'portalUrl' => $this->portalUrl,
                'registerUrl' => rtrim(dirname($this->portalUrl), '/').'/register',
                'company' => $this->company,
                'settings' => $this->settings,
            ],
        );
    }
}
