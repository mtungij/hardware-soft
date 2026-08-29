<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'user_id', 'subject_type', 'subject_id', 'event', 'metadata'])]
class CommercialConfigurationEvent extends Model
{
    use HasCompany;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
