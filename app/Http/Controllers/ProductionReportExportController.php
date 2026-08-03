<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\ProductionReportService;
use App\Services\ReportExportService;
use App\Support\CompanyFeatures;
use Illuminate\Http\Request;

class ProductionReportExportController extends Controller
{
    public function __invoke(Request $request, string $report, string $format, ProductionReportService $reports, ReportExportService $exports)
    {
        abort_unless($request->user()?->can('production.view_reports') && $request->user()?->can('production.export_reports'), 403);
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);
        $definition = $reports->definition($report);
        abort_if(($definition['cost'] ?? false) && ! $request->user()?->can('production.view_cost_reports'), 403);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer'], 'family_id' => ['nullable', 'integer'], 'product_id' => ['nullable', 'integer'], 'machine_id' => ['nullable', 'integer'],
            'mould_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:40'], 'group_by' => ['nullable', 'string', 'max:20'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        if (($validated['date_from'] ?? null) && ($validated['date_to'] ?? null)) {
            abort_if(now()->parse($validated['date_from'])->diffInDays(now()->parse($validated['date_to'])) > 366, 422, 'Exports are limited to 366 days.');
        }

        $data = $reports->report($report, $validated, export: true);
        $company = CompanyFeatures::currentCompany();
        $branch = ! empty($validated['branch_id']) ? Branch::query()->where('company_id', $company?->id)->find($validated['branch_id']) : null;
        $payload = [
            'title' => $definition['title'], 'header' => [
                'company_name' => $company?->company_name ?: config('app.name', 'HARDEX ERP'),
                'logo' => $company?->logo ? storage_path('app/public/'.$company->logo) : null,
                'phone' => $company?->phone, 'whatsapp' => $company?->whatsapp_number, 'email' => $company?->email,
                'address' => $company?->address, 'region' => $company?->region, 'district' => $company?->district,
                'date_range' => ($validated['date_from'] ?? 'Beginning').' to '.($validated['date_to'] ?? CompanyFeatures::localDate()),
                'branch_name' => $branch?->name ?: 'All accessible sites', 'stock_location' => 'All Locations',
                'printed_by' => $request->user()?->name ?: '—',
                'printed_date' => now($company?->timezone ?: config('app.timezone'))->format('Y-m-d H:i T'),
            ],
            'headers' => $data['headers'], 'rows' => $data['rows'],
            'totals' => [...($data['totals'] ?? []), ...($data['truncated'] ? ['Notice' => 'Export limited to the first 5,000 matching rows.'] : [])],
        ];

        if ($format === 'pdf') {
            return response($exports->generatePdf($definition['title'], $payload, $validated), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.str($definition['title'])->slug('_').'.pdf"',
            ]);
        }

        return $exports->generateExcel($definition['title'], $payload, $validated);
    }
}
