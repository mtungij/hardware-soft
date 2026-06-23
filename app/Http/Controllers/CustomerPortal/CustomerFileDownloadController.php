<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerDeposit;
use App\Models\CustomerReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerFileDownloadController extends Controller
{
    public function receipt(Request $request, CustomerReceipt $receipt): StreamedResponse
    {
        $this->authorizeCustomerFile($request, $receipt->customer_account_id);

        abort_unless(Storage::disk('local')->exists($receipt->receipt_file), 404);

        return Storage::disk('local')->download($receipt->receipt_file);
    }

    public function deposit(Request $request, CustomerDeposit $deposit): StreamedResponse
    {
        $this->authorizeCustomerFile($request, $deposit->customer_account_id);

        abort_unless(Storage::disk('local')->exists($deposit->receipt_file), 404);

        return Storage::disk('local')->download($deposit->receipt_file);
    }

    private function authorizeCustomerFile(Request $request, int $accountId): void
    {
        if (auth()->check()) {
            return;
        }

        abort_unless(auth('customer')->check() && auth('customer')->id() === $accountId, 403);
    }
}
