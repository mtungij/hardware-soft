<?php

namespace App\Http\Controllers\CustomerPortal;

use App\Http\Controllers\Controller;
use App\Models\CustomerDeposit;
use App\Models\CustomerReceipt;
use App\Support\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerFileDownloadController extends Controller
{
    public function receipt(Request $request, CustomerReceipt $receipt): StreamedResponse
    {
        $this->authorizeCustomerFile($request, $receipt, 'approve customer receipts');

        abort_unless(Storage::disk('local')->exists($receipt->receipt_file), 404);

        return Storage::disk('local')->download($receipt->receipt_file);
    }

    public function deposit(Request $request, CustomerDeposit $deposit): StreamedResponse
    {
        $this->authorizeCustomerFile($request, $deposit, 'approve customer deposits');

        abort_unless(Storage::disk('local')->exists($deposit->receipt_file), 404);

        return Storage::disk('local')->download($deposit->receipt_file);
    }

    private function authorizeCustomerFile(Request $request, CustomerReceipt|CustomerDeposit $file, string $permission): void
    {
        if ($request->is('customer/*')) {
            $account = auth('customer')->user();
            abort_unless($account && (int) $file->company_id === (int) $account->company_id
                && (int) $file->customer_id === (int) $account->customer_id
                && (int) $file->customer_account_id === (int) $account->id, 403);

            return;
        }
        $user = auth('web')->user();
        abort_unless($user && (int) $user->company_id === (int) $file->company_id && $user->can($permission)
            && BranchAccess::canAccessBranch($user, $file->branch_id), 403);
    }
}
