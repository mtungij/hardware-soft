<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsAppTemplate;

class WhatsAppTemplateService
{
    public const DEFAULTS = [
        'sale_completed' => ['sales', 'New Sale', "*NEW SALE*\n\nInvoice: {{sale_number}}\nDate: {{date}}\nBranch: {{branch}}\nCashier: {{cashier}}\nCustomer: {{customer}}\n\nItems: {{items}}\nTotal: {{currency}} {{total}}\nPaid: {{currency}} {{paid}}\nBalance: {{currency}} {{balance}}\n\nPayment: {{payment}}\n\nHARDEX POS"],
        'sale_cancelled' => ['security', 'Sale Cancelled', "HARDEX SECURITY ALERT\nSale {{sale_number}} was cancelled.\nBranch: {{branch}}\nAmount: TZS {{amount}}\nCancelled by: {{actor}}\nTime: {{time}}"],
        'customer_payment_received' => ['customer_payments', 'Customer Payment Received', "HARDEX CUSTOMER PAYMENT\nPayment received from {{customer}}.\nAmount: TZS {{amount}}\nReference: {{reference}}\nBranch: {{branch}}\nReceived by: {{actor}}"],
        'production_completed' => ['production', 'Production Completed', "HARDEX PRODUCTION COMPLETED\nOrder: {{order_number}}\nProduct: {{product}}\nAccepted: {{accepted}}\nRejected: {{rejected}}\nBranch: {{branch}}"],
    ];

    public function seedDefaults(Company $company): void
    {
        foreach (self::DEFAULTS as $key => [$category, $name, $body]) {
            WhatsAppTemplate::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['category' => $category, 'name' => $name, 'body' => $body, 'active' => true],
            );
        }
    }

    public function render(Company $company, string $key, array $variables): string
    {
        $default = self::DEFAULTS[$key][2] ?? '';
        $body = WhatsAppTemplate::withoutGlobalScopes()->where('company_id', $company->id)->where('key', $key)->where('active', true)->value('body') ?: $default;

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', fn (array $matches): string => array_key_exists($matches[1], $variables) ? (string) $variables[$matches[1]] : '', $body) ?: '';
    }
}
