<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOnboardingProgress extends Model
{
    protected $table = 'user_onboarding_progress';

    protected $fillable = [
        'user_id',
        'customer_account_id',
        'guard',
        'tour_name',
        'completed',
        'completed_at',
        'skipped',
        'last_step',
        'checklist',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
            'completed_at' => 'datetime',
            'skipped' => 'boolean',
            'last_step' => 'integer',
            'checklist' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }
}
