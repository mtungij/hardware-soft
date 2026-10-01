<?php

namespace App\Models;

use App\Models\Concerns\HasCompany;
use App\Support\WhatsAppCategories;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'branch_id', 'user_id', 'name', 'phone', 'scope', 'active', 'categories'])]
class WhatsAppRecipient extends Model
{
    use HasCompany;

    protected $table = 'whatsapp_recipients';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accepts(string $category, ?int $branchId): bool
    {
        if (! $this->active || ! in_array($category, $this->categories ?: [], true)) {
            return false;
        }

        return $this->scope === 'company' || $branchId === null || (int) $this->branch_id === $branchId;
    }

    protected static function booted(): void
    {
        $sanitize = function (self $model): void {
            if ($model->categories !== null) {
                $model->categories = WhatsAppCategories::filter($model->company_id, $model->categories);
            }
        };

        // Run after HasCompany assigns the tenant on creation.
        static::creating($sanitize);
        static::saving(function (self $model) use ($sanitize): void {
            if ($model->exists) {
                $sanitize($model);
            }
        });
    }

    public function getCategoriesAttribute($value): ?array
    {
        return $value === null ? null : WhatsAppCategories::filter($this->company_id, $this->fromJson($value));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'categories' => 'array'];
    }
}
