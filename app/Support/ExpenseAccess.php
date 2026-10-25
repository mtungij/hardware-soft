<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ExpenseAccess
{
    public static function authorizeEntry(?User $user): void
    {
        abort_unless($user?->company_id && ($user->can('view expenses') || $user->can('create expenses')), 403);
    }

    public static function authorize(?User $user, string $permission, ?Expense $expense = null): void
    {
        abort_unless($user?->company_id && $user->can($permission), 403);
        if ($expense) {
            abort_unless((int) $expense->company_id === (int) $user->company_id
                && BranchAccess::canAccessBranch($user, (int) $expense->branch_id), 403);
        }
    }

    public static function authorizeExport(?User $user): void
    {
        self::authorize($user, 'view expenses');
        self::authorize($user, 'export expenses');
    }

    public static function query(User $user): Builder
    {
        return BranchAccess::scopeAccessibleToUser(Expense::query(), $user);
    }
}
