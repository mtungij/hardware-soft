<?php

namespace App\Services;

use App\Models\Company;
use App\Models\WhatsAppRecipient;
use App\Support\UiText;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class WhatsAppStockAlertPdfService
{
    public function generate(Company $company, WhatsAppRecipient $recipient, CarbonInterface $generatedAt, Collection $rows, string $reference): string
    {
        return UiText::withLocale(app(WhatsAppLocalization::class)->language($company),
            fn () => $this->generateLocalized($company, $recipient, $generatedAt, $rows, $reference));
    }

    private function generateLocalized(Company $company, WhatsAppRecipient $recipient, CarbonInterface $generatedAt, Collection $rows, string $reference): string
    {
        $directory = 'whatsapp-reports/'.$company->id.'/'.$generatedAt->toDateString();
        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->makeDirectory('mpdf-temp');
        $relativePath = $directory.'/stock-alert-'.$reference.'-recipient-'.$recipient->id.'.pdf';
        $pdf = new Mpdf([
            'format' => 'A4-L',
            'tempDir' => Storage::disk('local')->path('mpdf-temp'),
        ]);
        $pdf->SetTitle(app(WhatsAppLocalization::class)->get($company, 'stock_alert.title').' '.$generatedAt->toDateString());
        $pdf->SetAuthor('HARDEX');
        $pdf->SetHTMLFooter('<div style="font-size:8px;color:#64748b;text-align:center">'.e(__('exports.footer', ['brand' => 'HARDEX POS'])).'</div>');
        $scopeLabel = app(WhatsAppStockAlertService::class)->scopeLabel($company, $recipient);
        $pdf->WriteHTML(view('pdf.whatsapp-stock-alert', compact('company', 'recipient', 'generatedAt', 'rows', 'scopeLabel'))->render());
        $pdf->Output(Storage::disk('local')->path($relativePath), Destination::FILE);

        return $relativePath;
    }
}
