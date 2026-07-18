<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_name',
    'company_id',
    'business_type',
    'tin_number',
    'vrn_number',
    'company_logo',
    'company_phone',
    'whatsapp_number',
    'company_email',
    'company_address',
    'region',
    'district',
    'country',
    'business_description',
    'currency',
    'timezone',
    'language',
    'receipt_footer_text',
    'tax_enabled',
    'enable_warehouse',
    'allow_direct_stock_in',
    'allow_sales_from_store',
    'inventory_mode',
    'allow_multiple_dispensing_locations',
    'credit_limit_enforcement',
    'allow_credit_sale_without_customer',
    'stock_adjustment_approval_required',
    'default_branch_id',
    'default_stock_location_id',
    'theme_color',
    'system_initialized',
    'mail_host',
    'mail_port',
    'mail_username',
    'mail_password',
    'mail_encryption',
    'mail_from_email',
    'mail_from_name',
])]
class Setting extends Model
{
    use HasCompany, HasFactory;

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    protected function casts(): array
    {
        return [
            'tax_enabled' => 'boolean',
            'enable_warehouse' => 'boolean',
            'allow_direct_stock_in' => 'boolean',
            'allow_sales_from_store' => 'boolean',
            'allow_multiple_dispensing_locations' => 'boolean',
            'allow_credit_sale_without_customer' => 'boolean',
            'stock_adjustment_approval_required' => 'boolean',
            'system_initialized' => 'boolean',
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
        ];
    }
}
