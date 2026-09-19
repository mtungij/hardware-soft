<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\QuotationDocumentService;
use App\Support\QuotationTemplateRegistry;
use Illuminate\Http\Request;

class QuotationTemplatePreviewController extends Controller
{
    public function __invoke(Request $request, string $template, QuotationDocumentService $documents)
    {
        $definition = QuotationTemplateRegistry::require($template);
        $document = $documents->sample(Company::findOrFail($request->user()->company_id));
        if ($request->boolean('download')) {
            return response($documents->pdf($document, $definition['key']), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="quotation-sample-'.$definition['key'].'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $document['preview'] = [
            'name' => $definition['name'],
            'download' => route('settings.quotation-templates.preview', ['template' => $definition['key'], 'download' => 1]),
            'back' => route('settings.quotation-templates'),
        ];

        return response($documents->html($document, $definition['key']))->header('Cache-Control', 'private, no-store');
    }
}
