<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\PurchaseOrderEmailService;

class PurchaseOrderPdfController extends Controller
{
    public function __invoke(Purchase $purchase, PurchaseOrderEmailService $service)
    {
        abort_unless(auth()->user()->can('send purchase emails') || auth()->user()->can('resend purchase emails'), 403);

        return response($service->pdfBinary($purchase), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-order-'.$purchase->reference_number.'.pdf"',
        ]);
    }
}
