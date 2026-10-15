<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\WhatsAppTemplate;

class WhatsAppTemplateService
{
    public const DEFAULTS = [
        'sale_completed' => ['sales', 'application.new_sale_66d89d8f', 'templates.sale_completed'],
        'sale_cancelled' => ['security', 'application.sale_cancelled_5115d4c9', 'templates.sale_cancelled'],
        'customer_payment_received' => ['customer_payments', 'application.customer_payment_received_6abcff2f', 'templates.customer_payment_received'],
        'purchase_order_created' => ['purchase_order_created', 'application.purchase_order_created_8baf36ac', 'templates.purchase_order_created'],
        'goods_received_grn' => ['goods_received_grn', 'application.goods_received_grn_41e4ab9a', 'templates.goods_received_grn'],
        'production_completed' => ['production', 'application.production_completed_3237a1ea', 'templates.production_completed'],
    ];

    public function __construct(private WhatsAppLocalization $localization) {}

    public function bodyForLanguage(WhatsAppTemplate $template, string $language): string
    {
        $localized = json_decode($template->body, true);
        if (is_array($localized) && isset($localized['translations'])) {
            $body = $localized['translations'][$language] ?? null;
        } else {
            // Existing templates predate language selection and were English templates.
            $body = $language === 'en' ? $template->body : null;
        }

        return is_string($body) && $body !== ''
            ? $body
            : $this->localization->getForLanguage($language, 'templates.'.$template->key);
    }

    public function saveBodyForLanguage(WhatsAppTemplate $template, string $language, string $body): void
    {
        $localized = json_decode($template->body, true);
        $translations = is_array($localized) && isset($localized['translations'])
            ? $localized['translations']
            : ['en' => $template->body];
        $translations[$language] = $body;
        $template->update(['body' => json_encode(['translations' => $translations], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    public function seedDefaults(Company $company): void
    {
        foreach (self::DEFAULTS as $key => [$category, $name, $body]) {
            WhatsAppTemplate::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['category' => $category, 'name' => __($name, [], 'en'), 'body' => $this->localization->getForLanguage('en', $body), 'active' => true],
            );
        }
    }

    public function render(Company $company, string $key, array $variables): string
    {
        $language = $this->localization->language($company);
        $default = $this->localization->get($company, 'templates.'.$key);
        $template = WhatsAppTemplate::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->where('key', $key)->where('active', true)->first();
        $stored = $template ? $this->bodyForLanguage($template, $language) : null;
        $requiresProducts = in_array($key, ['sale_completed', 'purchase_order_created', 'goods_received_grn'], true);
        $body = $requiresProducts && $stored && ! str_contains($stored, '{{products}}')
            ? $default
            : ($stored ?: $default);
        if ($key === 'sale_completed' && $stored && ! str_contains($stored, '{{total_quantity}}')) {
            $body = $default;
        }

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', fn (array $matches): string => array_key_exists($matches[1], $variables) ? (string) $variables[$matches[1]] : '', $body) ?: '';
    }
}
