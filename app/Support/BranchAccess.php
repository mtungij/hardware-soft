<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class BranchAccess
{
    // Explicit schema ownership; authentication and company-wide master data are excluded.
    public const DIRECT = [
        'Category', 'Customer', 'Product', 'Supplier', 'Sale', 'Purchase', 'Expense',
        'StockLocation', 'StockMovement', 'StockTransfer', 'StockAdjustment',
        'GoodsReceivingNote', 'GoodsReceivingNoteItem', 'Quotation', 'OpeningStock', 'InternalSale',
        'CustomerPayment', 'CustomerPaymentAllocation', 'SupplierPayment', 'CashbookSession',
        'CustomerReceipt', 'CustomerDeposit', 'CustomerPurchaseRequest', 'CustomerMaterialAccount',
        'CustomerMaterialCashTransaction', 'CustomerMaterialTransaction', 'CustomerMaterialIssue',
        'ProductLocationSetting', 'Machine', 'ProductionOrder', 'ProductionMachineAssignment',
        'ProductionCuringBatch', 'ProductionQualityInspection', 'ProductionQualityHold',
        'WhatsAppRecipient', 'WhatsAppNotification',
    ];

    public const PARENTS = [
        'SalesInvoice' => 'sale', 'SaleItem' => 'sale', 'SalePayment' => 'sale',
        'SaleAdditionalCharge' => 'sale', 'PurchaseItem' => 'purchase', 'PurchaseEmailLog' => 'purchase',
        'PurchaseItemCostBreakdown' => 'purchaseItem', 'StockTransferItem' => 'stockTransfer',
        'StockAdjustmentLine' => 'adjustment', 'InternalSaleItem' => 'internalSale',
        'OpeningStockLine' => 'openingStock', 'QuotationItem' => 'quotation',
        'QuotationAdditionalCharge' => 'quotation', 'GoodsReceiptAdditionalCost' => 'goodsReceivingNote',
        'CustomerAccount' => 'customer', 'CustomerNotification' => 'customer',
        'CustomerMessage' => 'customer', 'CustomerPortalSecurityEvent' => 'customer',
        'CustomerDepositUsage' => 'sale', 'CustomerPurchaseRequestItem' => 'purchaseRequest',
        'CustomerMaterialPlanLine' => 'account', 'CustomerMaterialAudit' => 'account',
        'CustomerMaterialIssueLine' => 'issue', 'ProductUnitConversion' => 'product',
        'LocationPrice' => 'stockLocation', 'UserStockLocation' => 'stockLocation', 'AnnouncementCustomer' => 'customer', 'ProductionOrderMaterial' => 'order',
        'ProductionOrderRecipeSnapshot' => 'order', 'ProductionOrderCosting' => 'productionOrder',
        'ProductionOrderCostingLine' => 'costing', 'ProductionOrderCostingEvent' => 'costing',
        'ProductionCuringAction' => 'batch', 'ProductionCuringRelease' => 'batch',
        'ProductionQualityInspectionResult' => 'inspection', 'ProductionQualityAttachment' => 'inspection',
        'ProductionQualityAuditEvent' => 'inspection', 'ProductionMouldInstallation' => 'machine',
    ];

    public static function staff(): ?User
    {
        // Customer portal requests keep customer access independent of a staff session.
        if (app()->bound('request') && request()->is('customer', 'customer/*', 'api/customer/*')) {
            return null;
        }
        // Only read cached guard identities, avoiding provider/global-scope recursion.
        foreach (array_unique(['web', Auth::getDefaultDriver()]) as $name) {
            $guard = Auth::guard($name);
            if ($guard->hasUser() && $guard->user() instanceof User) {
                return $guard->user();
            }
        }

        return null;
    }

    public static function restricted(?User $user = null): bool
    {
        $user ??= self::staff();

        return $user && ! $user->is_system_owner && ! $user->hasRole('Super Admin', 'web') && $user->branch_id !== null;
    }

    public static function branches(User $user): Builder
    {
        return Branch::withoutGlobalScopes()->where('company_id', $user->company_id)
            ->when(self::restricted($user), fn ($query) => $query->whereKey($user->branch_id));
    }

    public static function canAccessBranch(User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return ! self::restricted($user);
        }

        return self::branches($user)->whereKey($branchId)->exists();
    }

    public static function resolve(User $user, ?int $requested = null): ?int
    {
        if ($requested !== null && ! self::canAccessBranch($user, $requested)) {
            throw ValidationException::withMessages(['branch_id' => __('application.you_cannot_access_the_selected_branch_adb0a734')]);
        }

        return self::restricted($user) ? (int) $user->branch_id : $requested;
    }

    public static function scopeAccessibleToUser(Builder $query, User $user, string $prefix = ''): Builder
    {
        $model = $query->getModel();
        if (! $user->company_id) {
            return $query->whereRaw('1 = 0');
        }
        $query->where($prefix ? $prefix.'company_id' : $model->qualifyColumn('company_id'), $user->company_id);
        if (! self::restricted($user)) {
            return $query;
        }
        if ($column = self::ownership($model)) {
            return $query->where($prefix ? $prefix.$column : $model->qualifyColumn($column), $user->branch_id);
        }
        if ($parent = self::PARENTS[class_basename($model)] ?? null) {
            return $query->whereHas($parent, fn ($parentQuery) => self::scopeAccessibleToUser($parentQuery, $user));
        }

        return $query;
    }

    public static function ownership(Model $model): ?string
    {
        $name = class_basename($model);

        return $name === 'Branch' ? 'id' : (in_array($name, self::DIRECT, true) ? 'branch_id' : null);
    }

    public static function apply(Builder $query, Model $model, User $user): void
    {
        if (! self::restricted($user)) {
            return;
        }
        if ($column = self::ownership($model)) {
            $query->where($model->qualifyColumn($column), $user->branch_id);
        } elseif ($parent = self::PARENTS[class_basename($model)] ?? null) {
            $query->whereHas($parent, fn ($parentQuery) => self::scopeAccessibleToUser($parentQuery, $user));
        }
    }

    public static function validateWrite(Model $model): void
    {
        $user = self::staff();
        if (! $user || $user->is_system_owner) {
            return;
        }
        if (array_key_exists('company_id', $model->getAttributes()) && (int) $model->company_id !== (int) $user->company_id) {
            throw ValidationException::withMessages(['company_id' => __('application.the_record_must_belong_to_your_company_cd4e5472')]);
        }
        if (self::ownership($model) === 'branch_id') {
            if (! $model->exists && $model->branch_id === null && self::restricted($user)) {
                $model->branch_id = $user->branch_id;
            }
            if ((int) $model->company_id !== (int) $user->company_id
                || ! self::canAccessBranch($user, $model->branch_id === null ? null : (int) $model->branch_id)) {
                throw ValidationException::withMessages(['branch_id' => __('application.the_record_must_belong_to_an_accessible_company_branch_6204bf3a')]);
            }
            if ($model->exists && self::restricted($user) && (int) $model->getRawOriginal('branch_id') !== (int) $user->branch_id) {
                throw ValidationException::withMessages(['branch_id' => __('application.you_cannot_modify_another_branch_s_record_61a8085d')]);
            }
        }
        if ($model instanceof User && $model->branch_id !== null
            && (! Branch::withoutGlobalScopes()->where('company_id', $model->company_id)->whereKey($model->branch_id)->exists()
                || ! self::canAccessBranch($user, (int) $model->branch_id))) {
            throw ValidationException::withMessages(['branch_id' => __('application.assigned_branch_must_belong_to_the_user_s_company_97a1fb43')]);
        }
        // Validate branch-owned foreign IDs as well as the record's own branch.
        foreach (['product', 'customer', 'supplier', 'category', 'unit', 'measurementType',
            'productUnitConversion', 'transactionUnit', 'purchaseUnit', 'stockUnit', 'sellingUnit', 'baseUnit',
            'stockLocation', 'fromLocation', 'toLocation',
            'sourceLocation', 'destinationLocation', 'purchase', 'sale', 'defaultStockLocation'] as $name) {
            if (! method_exists($model, $name)) {
                continue;
            }
            $relation = $model->{$name}();
            if ($relation instanceof BelongsTo
                && $model->getAttribute($relation->getForeignKeyName()) !== null
                && ! $relation->exists()) {
                throw ValidationException::withMessages([$relation->getForeignKeyName() => __('application.the_selected_record_is_outside_your_accessible_company_branch_7d6f167c')]);
            }
        }
        if ($parent = self::PARENTS[class_basename($model)] ?? null) {
            if (self::restricted($user) && ! $model->{$parent}()->exists()) {
                throw ValidationException::withMessages(['branch_id' => __('application.the_parent_record_is_outside_your_accessible_branch_e2c330af')]);
            }
        }
    }
}
