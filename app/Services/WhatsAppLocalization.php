<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;

class WhatsAppLocalization
{
    public function language(Company|CompanyWhatsAppSetting $context): string
    {
        if ($context instanceof CompanyWhatsAppSetting) {
            return $context->notificationLanguage();
        }

        $value = CompanyWhatsAppSetting::withoutGlobalScopes()
            ->where('company_id', $context->id)
            ->value('whatsapp_notification_language');

        return in_array($value, ['en', 'sw'], true) ? $value : 'en';
    }

    public function get(Company|CompanyWhatsAppSetting $context, string $key, array $replace = []): mixed
    {
        return $this->getForLanguage($this->language($context), $key, $replace);
    }

    public function getForLanguage(?string $language, string $key, array $replace = []): mixed
    {
        return Lang::get('whatsapp.'.$key, $replace, in_array($language, ['en', 'sw'], true) ? $language : 'en');
    }

    public function date(Company|CompanyWhatsAppSetting $context, ?CarbonInterface $date, bool $withTime = false): string
    {
        return $this->dateForLanguage($this->language($context), $date, $withTime);
    }

    public function dateForLanguage(?string $language, ?CarbonInterface $date, bool $withTime = false): string
    {
        if (! $date) {
            return '-';
        }

        $formatted = $date->format('d').' '.$this->getForLanguage($language, 'months.short.'.$date->format('n')).' '.$date->format('Y');

        return $withTime ? $formatted.' '.$date->format('H:i') : $formatted;
    }
}
