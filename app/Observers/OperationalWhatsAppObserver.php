<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\CustomerMaterialCashTransaction;
use App\Models\CustomerMaterialIssue;
use App\Models\GoodsReceivingNote;
use App\Models\ProductionCuringAction;
use App\Models\ProductionCuringRelease;
use App\Models\WhatsAppRecipient;
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

        if ($model instanceof GoodsReceivingNote && $model->status === 'posted') {
            $model->loadMissing(['purchase.supplier', 'branch']);
            $service->queueForRecipients($company, 'purchases', 'goods_received', "grn:{$model->id}:received", implode("\n", [
                'HARDEX GOODS RECEIVED', "GRN: {$model->grn_number}",
                'Supplier: '.($model->purchase?->supplier?->name ?: '-'), 'Branch: '.($model->branch?->name ?: '-'),
                'Received: '.optional($model->received_date)->format('d M Y'),
            ]), (int) $model->branch_id, metadata: ['goods_receiving_note_id' => $model->id]);
        }

        if ($model instanceof CustomerMaterialCashTransaction && $model->transaction_type === 'deposit') {
            $model->loadMissing(['account.customer', 'branch']);
            $service->queueForRecipients($company, 'customer_materials', 'customer_material_deposit', "customer-material-deposit:{$model->id}:received", function (WhatsAppRecipient $recipient) use ($model): string {
                $lines = ['HARDEX MATERIAL DEPOSIT', 'Account: '.($model->account?->reference_number ?: '-'), 'Customer: '.($model->account?->customer?->name ?: '-'), 'Reference: '.$model->reference_number];
                if ($recipient->user?->can('customer_material_accounts.view')) {
                    $lines[] = 'Amount: TZS '.number_format((float) $model->amount, 0);
                }

                return implode("\n", $lines);
            }, (int) $model->branch_id, metadata: ['customer_material_cash_transaction_id' => $model->id]);
        }

        if ($model instanceof CustomerMaterialIssue) {
            $model->loadMissing(['account.customer', 'branch']);
            $service->queueForRecipients($company, 'customer_materials', 'customer_material_issue', "customer-material-issue:{$model->id}:posted", function (WhatsAppRecipient $recipient) use ($model): string {
                $lines = ['HARDEX MATERIAL ISSUE', 'Reference: '.$model->reference_number, 'Customer: '.($model->account?->customer?->name ?: '-'), 'Branch: '.($model->branch?->name ?: '-')];
                if ($recipient->user?->can('customer_material_accounts.view')) {
                    $lines[] = 'Value: TZS '.number_format((float) $model->total_value, 0);
                }

                return implode("\n", $lines);
            }, (int) $model->branch_id, metadata: ['customer_material_issue_id' => $model->id]);
        }

        if ($model instanceof ProductionCuringRelease) {
            $model->loadMissing(['batch.product', 'destinationLocation']);
            $service->queueForRecipients($company, 'production', 'curing_release', "curing-release:{$model->id}:posted", implode("\n", [
                'HARDEX CURING RELEASE', 'Batch: '.($model->batch?->batch_number ?: '-'),
                'Product: '.($model->batch?->product?->name ?: '-'), 'Quantity: '.$model->released_quantity,
                'Destination: '.($model->destinationLocation?->name ?: '-'),
            ]), (int) $model->batch?->branch_id, metadata: ['curing_release_id' => $model->id]);
        }

        if ($model instanceof ProductionCuringAction && $model->action_type === ProductionCuringAction::DAMAGE) {
            $model->loadMissing(['batch.product']);
            $service->queueForRecipients($company, 'production', 'curing_damage', "curing-action:{$model->id}:damage", implode("\n", [
                'HARDEX CURING DAMAGE', 'Batch: '.($model->batch?->batch_number ?: '-'),
                'Product: '.($model->batch?->product?->name ?: '-'), 'Quantity: '.$model->quantity,
                'Reason: '.($model->reason ?: '-'),
            ]), (int) $model->batch?->branch_id, metadata: ['curing_action_id' => $model->id]);
        }
    }
}
