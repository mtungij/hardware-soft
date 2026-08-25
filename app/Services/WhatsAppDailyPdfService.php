<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsAppRecipient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class WhatsAppDailyPdfService
{
    public function generate(Company $company, WhatsAppRecipient $recipient, CarbonInterface $date, string $summary): string
    {
        $directory = 'whatsapp-reports/'.$company->id.'/'.$date->toDateString();
        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $relativePath = $directory.'/daily-summary-recipient-'.$recipient->id.'.pdf';
        $path = Storage::disk('local')->path($relativePath);
        $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('mpdf-temp')]);
        $pdf->SetTitle('HARDEX Daily Summary '.$date->toDateString());
        $pdf->WriteHTML('<html><body style="font-family: sans-serif"><h1>'.e($company->company_name).'</h1><div style="white-space: pre-line; line-height: 1.6">'.e($summary).'</div></body></html>');
        $pdf->Output($path, Destination::FILE);

        return $relativePath;
    }
}
