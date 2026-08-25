<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'key', 'category', 'name', 'body', 'active'])]
class WhatsAppTemplate extends Model
{
    use HasCompany;

    protected $table = 'whatsapp_templates';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
