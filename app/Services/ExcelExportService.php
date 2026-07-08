<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportService
{
    public function generateExcel(string $filename, array $payload): StreamedResponse
    {
        return new StreamedResponse(function () use ($payload): void {
            $handle = fopen('php://output', 'w');
            $header = $payload['header'];

            fputcsv($handle, [$header['company_name']]);
            fputcsv($handle, [trim(($header['phone'] ?: '-').' / '.($header['whatsapp'] ?: '-').' / '.($header['email'] ?: '-'))]);
            fputcsv($handle, [trim(($header['address'] ?: '-').' / '.($header['region'] ?: '-').' / '.($header['district'] ?: '-'))]);
            fputcsv($handle, [$payload['title']]);
            fputcsv($handle, ['Date Range: '.$header['date_range'], 'Branch: '.$header['branch_name'], 'Exported By: '.$header['printed_by'], 'Exported At: '.$header['printed_date']]);
            fputcsv($handle, []);
            fputcsv($handle, $payload['headers']);

            foreach ($payload['rows'] as $row) {
                fputcsv($handle, $row);
            }

            if (($payload['totals'] ?? []) !== []) {
                fputcsv($handle, []);
                foreach ($payload['totals'] as $label => $value) {
                    fputcsv($handle, [$label, $value]);
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
