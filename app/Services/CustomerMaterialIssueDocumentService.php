<?php

namespace App\Services;

use App\Models\CustomerMaterialIssue;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class CustomerMaterialIssueDocumentService
{
    public function data(CustomerMaterialIssue $issue): array
    {
        $issue->loadMissing([
            'account.company', 'account.customer', 'account.transactions',
            'branch', 'stockLocation', 'issuedBy', 'lines',
        ]);

        $previousBalance = 0.0;
        $remainingBalance = 0.0;

        foreach ($issue->account->transactions->sortBy(fn ($row) => [$row->transacted_at, $row->id]) as $transaction) {
            $isThisIssue = $transaction->source_type === CustomerMaterialIssue::class
                && (int) $transaction->source_id === (int) $issue->id;

            if ($isThisIssue) {
                $previousBalance = $remainingBalance;
            }

            $remainingBalance += (float) $transaction->credit_amount - (float) $transaction->debit_amount;

            if ($isThisIssue) {
                break;
            }
        }

        return [
            'issue' => $issue,
            'account' => $issue->account,
            'previousBalance' => round($previousBalance, 2),
            'remainingBalance' => round($remainingBalance, 2),
        ];
    }

    public function pdf(CustomerMaterialIssue $issue): string
    {
        $data = $this->data($issue);
        $path = 'customer-material-issues/'.$issue->company_id.'/'.$issue->reference_number.'.pdf';

        if (Storage::disk('local')->exists($path)) {
            return $path;
        }

        Storage::disk('local')->makeDirectory(dirname($path));
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $pdf = new Mpdf([
            'format' => [80, 200],
            'margin_left' => 4,
            'margin_right' => 4,
            'margin_top' => 5,
            'margin_bottom' => 5,
            'tempDir' => Storage::disk('local')->path('mpdf-temp'),
        ]);
        $pdf->SetTitle($issue->reference_number);
        $pdf->SetAuthor('HARDEX');
        $pdf->WriteHTML(view('pdf.customer-material-issue-receipt', $data)->render());
        $pdf->Output(Storage::disk('local')->path($path), Destination::FILE);

        return $path;
    }
}
