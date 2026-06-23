<?php

namespace App\Services;

use App\Jobs\SendPurchaseOrderJob;
use App\Models\Purchase;
use App\Models\PurchaseEmailLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;

class PurchaseOrderEmailService
{
    public function validateCanSend(Purchase $purchase): string
    {
        $purchase->loadMissing('supplier');

        if (! in_array($purchase->status, ['draft', 'ordered'], true)) {
            throw ValidationException::withMessages(['purchase' => 'Only draft or ordered purchase orders can be emailed.']);
        }

        $email = $purchase->supplier?->email;

        if (blank($email) || Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            throw ValidationException::withMessages(['supplier' => 'Supplier email is empty or invalid.']);
        }

        return $email;
    }

    public function queue(Purchase $purchase, int $sentBy): PurchaseEmailLog
    {
        $recipient = $this->validateCanSend($purchase);
        $subject = "Purchase Order {$purchase->reference_number} - ".($this->settings()?->company_name ?? config('app.name'));

        $log = PurchaseEmailLog::create([
            'purchase_id' => $purchase->id,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'status' => 'pending',
            'sent_by' => $sentBy,
        ]);

        $purchase->update([
            'email_status' => 'pending',
            'email_recipient' => $recipient,
            'email_sent_by' => $sentBy,
        ]);

        SendPurchaseOrderJob::dispatch($purchase->id, $log->id);

        return $log;
    }

    public function pdfBinary(Purchase $purchase): string
    {
        $purchase->loadMissing(['supplier', 'branch', 'items.product.unit']);
        $settings = $this->settings();
        $html = view('pdf.purchase-order', compact('purchase', 'settings'))->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_left' => 12,
            'margin_right' => 12,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    public function settings(): ?Setting
    {
        return Setting::query()->first();
    }
}
