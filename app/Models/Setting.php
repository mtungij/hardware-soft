<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_name',
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
    use HasFactory;

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
            'system_initialized' => 'boolean',
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
        ];
    }
}
