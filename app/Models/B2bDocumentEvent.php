<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'document_type', 'document_id', 'event', 'actor_type', 'actor_id', 'reason', 'metadata'])]
class B2bDocumentEvent extends Model
{
    use HasCompany;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
