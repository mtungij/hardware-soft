<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelExportService
{
    public function generateExcel(string $filename, array $payload): StreamedResponse
    {
        $locale = app()->getLocale();

        return new StreamedResponse(function () use ($payload, $locale): void {
            $handle = fopen('php://output', 'w');
            $header = $payload['header'];

            fputcsv($handle, [$header['company_name']]);
            fputcsv($handle, [trim(($header['phone'] ?: '-').' / '.($header['whatsapp'] ?: '-').' / '.($header['email'] ?: '-'))]);
            fputcsv($handle, [trim(($header['address'] ?: '-').' / '.($header['region'] ?: '-').' / '.($header['district'] ?: '-'))]);
            fputcsv($handle, [$payload['title']]);
            fputcsv($handle, [__('exports.date_range', [], $locale).': '.$header['date_range'], __('exports.branch', [], $locale).': '.$header['branch_name'], __('exports.exported_by', [], $locale).': '.$header['printed_by'], __('exports.exported_at', [], $locale).': '.$header['printed_date']]);
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
