<?php

namespace App\Support;

use App\Models\Company;

final class WhatsAppCategories
{
    // All current manufacturing events subscribe through this category.
    public const MANUFACTURING = ['production'];

    public const LABELS = [
        'daily_summary' => 'Daily Management Summary',
        'stock_alerts' => 'Low / Out of Stock',
        'sales' => 'Sales',
        'security' => 'Cancellation / Security',
        'customer_payments' => 'Customer Payments',
        'customer_debt' => 'Customer Debt Reminders',
        'purchases' => 'Purchases / Goods Received',
        'purchase_order_created' => 'Purchase Order Created',
        'goods_received_grn' => 'Goods Received / GRN',
        'customer_materials' => 'Customer Material Accounts',
        'production' => 'Production / Curing',
        'customer_requests' => 'Customer Request Alerts',
        'quotations' => 'Quotation Sent to Customer',
        'quotation_acceptance' => 'Quotation Accepted',
        'customer_invoices' => 'Final Invoice to Customer',
        'customer_portal' => 'Customer Portal Credentials',
    ];

    public static function allows(?int $companyId, string $category): bool
    {
        return ! in_array($category, self::MANUFACTURING, true)
            || (bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('manufacturing_enabled');
    }

    public static function filter(?int $companyId, array $categories): array
    {
        if (! array_intersect($categories, self::MANUFACTURING) || self::allows($companyId, 'production')) {
            return array_values($categories);
        }

        return array_values(array_diff($categories, self::MANUFACTURING));
    }

    public static function available(?int $companyId): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::filter($companyId, array_keys(self::LABELS))));
    }
}
