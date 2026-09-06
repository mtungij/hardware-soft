<?php

namespace App\Services;

use App\Models\CompanyWhatsAppSetting;
use App\Models\CustomerMaterialIssue;
use App\Models\WhatsAppNotification;
use App\Support\WhatsAppPhone;
use Throwable;

class CustomerMaterialIssueCommunicationService
{
    public function __construct(
        private WhatsAppNotificationService $notifications,
        private WhatsAppMessageFactory $messages,
        private CustomerMaterialIssueDocumentService $documents,
    ) {}

    public function queueCustomerReceipt(CustomerMaterialIssue $issue): ?WhatsAppNotification
    {
        $issue->loadMissing(['company', 'account.customer']);
        $setting = CompanyWhatsAppSetting::withoutGlobalScopes()
            ->where('company_id', $issue->company_id)
            ->first();

        if (! $setting?->ready() || ! $setting->categoryEnabled('customer_materials')) {
            return null;
        }

        $phone = $issue->account->customer?->phone;
        try {
            $phone = WhatsAppPhone::normalize((string) $phone);
        } catch (Throwable) {
            return null;
        }

        return $this->notifications->queuePhone(
            company: $issue->company,
            setting: $setting,
            phone: $phone,
            category: 'customer_materials',
            notificationType: 'customer_material_issue_receipt',
            eventKey: "customer-material-issue:{$issue->company_id}:{$issue->id}:customer",
            message: $this->messages->materialIssue($issue, $setting),
            branchId: (int) $issue->branch_id,
            metadata: ['customer_material_issue_id' => $issue->id, 'reference_number' => $issue->reference_number],
            attachmentPath: $this->documents->pdf($issue),
            attachmentType: 'file',
            idempotencyRecipientToken: 'customer-'.$issue->account->customer_id,
        );
    }
}
