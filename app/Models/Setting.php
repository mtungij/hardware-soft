<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_name',
    'company_logo',
    'company_phone',
    'company_email',
    'company_address',
    'currency',
    'receipt_footer_text',
    'tax_enabled',
    'default_branch_id',
    'theme_color',
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
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
        ];
    }
}
