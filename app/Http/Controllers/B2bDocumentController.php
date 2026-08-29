<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Services\B2bDocumentPdfService;
use App\Support\AuthorizationScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class B2bDocumentController extends Controller
{
    public function quotation(Request $request, Quotation $quotation, B2bDocumentPdfService $pdfs): StreamedResponse
    {
        $this->authorizeDocument($request, $quotation->company_id, $quotation->branch_id, $quotation->customer_id, 'quotations.view');
        $path = $quotation->pdf_path ?: $pdfs->quotation($quotation);
        if ($quotation->pdf_path !== $path) {
            $quotation->update(['pdf_path' => $path]);
        }

        return Storage::disk('local')->download($path, $quotation->quotation_number.'.pdf');
    }

    public function invoice(Request $request, SalesInvoice $invoice, B2bDocumentPdfService $pdfs): StreamedResponse
    {
        $invoice->loadMissing('sale');
        $this->authorizeDocument($request, $invoice->company_id, $invoice->sale->branch_id, $invoice->customer_id, 'invoices.view');
        $path = $invoice->pdf_path ?: $pdfs->invoice($invoice);
        if ($invoice->pdf_path !== $path) {
            $invoice->update(['pdf_path' => $path, 'generated_at' => now()]);
        }

        return Storage::disk('local')->download($path, $invoice->invoice_number.'.pdf');
    }

    private function authorizeDocument(Request $request, int $companyId, int $branchId, ?int $customerId, string $permission): void
    {
        if ($account = auth('customer')->user()) {
            if ((int) $account->company_id !== $companyId || (int) $account->customer_id !== (int) $customerId) {
                throw new AuthorizationException;
            }

            return;
        }
        $user = $request->user();
        if (! $user || (int) $user->company_id !== $companyId || ! $user->can($permission)) {
            throw new AuthorizationException;
        }
        if (AuthorizationScope::scopeFor($user, 'report_scope', AuthorizationScope::BRANCH) !== AuthorizationScope::COMPANY
            && (int) $user->branch_id !== $branchId) {
            throw new AuthorizationException;
        }
    }
}
