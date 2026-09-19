<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Validation\ValidationException;

final class QuotationTemplateRegistry
{
    public static function all(): array
    {
        return array_filter(config('document_templates.quotation', []), fn (array $template) => $template['active']);
    }

    public static function require(string $key): array
    {
        $template = self::all()[$key] ?? null;
        if (! $template) {
            throw ValidationException::withMessages(['quotation_template_key' => 'Select a registered quotation template.']);
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
