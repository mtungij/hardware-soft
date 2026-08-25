<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'user_id', 'action', 'before', 'after', 'ip_address'])]
class WhatsAppSettingAudit extends Model
{
    use HasCompany;

    protected $table = 'whatsapp_setting_audits';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }
}
