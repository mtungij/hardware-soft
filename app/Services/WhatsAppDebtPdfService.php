<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsAppRecipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class WhatsAppDebtPdfService
{
    public function generate(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date, Collection $debts): string
    {
        $directory = 'whatsapp-reports/'.$company->id.'/'.$date->toDateString();
        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $relativePath = $directory.'/management-debt-summary-recipient-'.$recipient->id.'.pdf';
        $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('mpdf-temp')]);
        $pdf->SetTitle('HARDEX Management Debt Summary '.$date->toDateString());
        $pdf->SetAuthor('HARDEX');
        $pdf->WriteHTML(view('pdf.whatsapp-debt-summary', compact('company', 'recipient', 'date', 'debts'))->render());
        $pdf->Output(Storage::disk('local')->path($relativePath), Destination::FILE);

        return $relativePath;
    }
}
