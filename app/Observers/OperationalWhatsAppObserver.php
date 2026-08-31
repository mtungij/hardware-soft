<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\GoodsReceivingNote;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringRelease;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsAppLocalization;
use App\Services\WhatsAppNotificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class OperationalWhatsAppObserver implements ShouldHandleEventsAfterCommit
{
    public function created(object $model): void
    {
        $company = Company::query()->find($model->company_id);
        if (! $company) {
            return;
        }

        $service = app(WhatsAppNotificationService::class);
        $localization = app(WhatsAppLocalization::class);
        $label = fn (string $key): string => $localization->get($company, 'operational.'.$key);

        if ($model instanceof GoodsReceivingNote && $model->status === 'posted') {
            $model->loadMissing(['purchase.supplier', 'branch']);
            $service->queueForRecipients($company, 'purchases', 'goods_received', "grn:{$model->id}:received", implode("\n", [
                $label('goods_received_title'), "GRN: {$model->grn_number}",
                $label('supplier').': '.($model->purchase?->supplier?->name ?: '-'), $label('branch').': '.($model->branch?->name ?: '-'),
                $label('received').': '.$localization->date($company, $model->received_date),
            ]), (int) $model->branch_id, metadata: ['goods_receiving_note_id' => $model->id]);
        }

        if ($model instanceof CustomerMaterialCashTransaction && $model->transaction_type === 'deposit') {
            $model->loadMissing(['account.customer', 'branch']);
            $service->queueForRecipients($company, 'customer_materials', 'customer_material_deposit', "customer-material-deposit:{$model->id}:received", function (WhatsAppRecipient $recipient) use ($model, $label): string {
                $lines = [$label('material_deposit_title'), $label('account').': '.($model->account?->reference_number ?: '-'), $label('customer').': '.($model->account?->customer?->name ?: '-'), $label('reference').': '.$model->reference_number];
                if ($recipient->user?->can('customer_material_accounts.view')) {
                    $lines[] = $label('amount').': TZS '.number_format((float) $model->amount, 0);
                }

                return implode("\n", $lines);
            }, (int) $model->branch_id, metadata: ['customer_material_cash_transaction_id' => $model->id]);
        }

        if ($model instanceof CustomerMaterialIssue) {
            $model->loadMissing(['account.customer', 'branch']);
            $service->queueForRecipients($company, 'customer_materials', 'customer_material_issue', "customer-material-issue:{$model->id}:posted", function (WhatsAppRecipient $recipient) use ($model, $label): string {
                $lines = [$label('material_issue_title'), $label('reference').': '.$model->reference_number, $label('customer').': '.($model->account?->customer?->name ?: '-'), $label('branch').': '.($model->branch?->name ?: '-')];
                if ($recipient->user?->can('customer_material_accounts.view')) {
                    $lines[] = $label('value').': TZS '.number_format((float) $model->total_value, 0);
                }

                return implode("\n", $lines);
            }, (int) $model->branch_id, metadata: ['customer_material_issue_id' => $model->id]);
        }

        if ($model instanceof ProductionCuringRelease) {
            $model->loadMissing(['batch.product', 'destinationLocation']);
            $service->queueForRecipients($company, 'production', 'curing_release', "curing-release:{$model->id}:posted", implode("\n", [
                $label('curing_release_title'), $label('batch').': '.($model->batch?->batch_number ?: '-'),
                $label('product').': '.($model->batch?->product?->name ?: '-'), $label('quantity').': '.$model->released_quantity,
                $label('destination').': '.($model->destinationLocation?->name ?: '-'),
            ]), (int) $model->batch?->branch_id, metadata: ['curing_release_id' => $model->id]);
        }

        if ($model instanceof ProductionCuringAction && $model->action_type === ProductionCuringAction::DAMAGE) {
            $model->loadMissing(['batch.product']);
            $service->queueForRecipients($company, 'production', 'curing_damage', "curing-action:{$model->id}:damage", implode("\n", [
                $label('curing_damage_title'), $label('batch').': '.($model->batch?->batch_number ?: '-'),
                $label('product').': '.($model->batch?->product?->name ?: '-'), $label('quantity').': '.$model->quantity,
                $label('reason').': '.($model->reason ?: '-'),
            ]), (int) $model->batch?->branch_id, metadata: ['curing_action_id' => $model->id]);
        }
    }
}
