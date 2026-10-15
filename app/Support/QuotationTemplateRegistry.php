<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Validation\ValidationException;

final class QuotationTemplateRegistry
{
    public static function all(): array
    {
        return array_map(function (array $template): array {
            $template['name'] = __($template['name']);
            $template['description'] = __($template['description']);

            return $template;
        }, array_filter(config('document_templates.quotation', []), fn (array $template) => $template['active']));
    }

    public static function require(string $key): array
    {
        $template = self::all()[$key] ?? null;
        if (! $template) {
            throw ValidationException::withMessages(['quotation_template_key' => __('application.select_a_registered_quotation_template_c5ce2b72')]);
        }

        return $template;
    }

    public static function saved(?string $key): array
    {
        return self::all()[$key ?? 'classic'] ?? self::require('classic');
    }

    public static function forNew(int $companyId, ?string $selection): string
    {
        if (filled($selection)) {
            return self::require($selection)['key'];
        }

        return self::saved(Company::findOrFail($companyId)->quotation_template_key)['key'];
    }
}
