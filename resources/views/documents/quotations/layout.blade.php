<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quotation · {{ $document['number'] }}</title>
<style>
@page { footer: html_quotationFooter; margin-top: 14mm; margin-right: 14mm; margin-bottom: 18mm; margin-left: 14mm; }
body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #243247; background: #fff; line-height: 1.45; }
.document { max-width: 182mm; margin: 0 auto; }
table { border-collapse: collapse; width: 100%; }
td { vertical-align: top; }
h1,h2,h3,p { margin: 0; }
h1 { font-size: 25pt; letter-spacing: 1px; line-height: 1.2; }
h2 { font-size: 17pt; line-height: 1.3; }
h3,.label { font-size: 8pt; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
.muted { color: #526174; }
.logo { max-height: 18mm; max-width: 40mm; margin-bottom: 2mm; }
.company-name { font-size: 18pt; font-weight: bold; line-height: 1.3; }
.contact { font-size: 8pt; margin-top: 2mm; }
.title { margin: 6mm 0; }
.reference { font-weight: bold; font-size: 12pt; margin-top: 2mm; }
.brief { margin: 6mm 0; }
.brief td { padding: 3mm; width: 50%; }
.metadata td { padding: 1mm 0; font-size: 8pt; }
.metadata td:first-child { width: 35%; color: #526174; }
.customer-name { font-size: 12pt; font-weight: bold; margin: 1.5mm 0; }
.items { table-layout: fixed; margin-top: 4mm; font-size: 8pt; }
.items th { text-align: left; padding: 2.5mm 1.5mm; background: #edf1f5; border-bottom: 1px solid #9aa7b5; font-size: 7.5pt; }
.items td { padding: 2.8mm 1.5mm; border-bottom: 1px solid #d8dee6; overflow-wrap: anywhere; word-wrap: break-word; }
.items .numeric { text-align: right; }
.items .sku { font-size: 7pt; color: #526174; margin-top: 1mm; }
thead { display: table-header-group; } tr { page-break-inside: avoid; }
.currency { font-size: 7.5pt; text-align: right; color: #526174; margin-top: 3mm; }
.summary { width: 53%; margin: 3mm 0 3mm auto; page-break-inside: avoid; }
.summary td { padding: 1.8mm; border-bottom: 1px solid #e2e8f0; }
.summary .amount { text-align: right; white-space: nowrap; }
.summary .grand td { border-top: 2px solid #243247; border-bottom: 0; font-size: 11pt; font-weight: bold; padding-top: 3mm; }
.section { margin-top: 3mm; page-break-inside: avoid; }
.section h3 { margin-bottom: 2mm; }
.prose { white-space: pre-wrap; overflow-wrap: anywhere; }
.closing { page-break-inside: avoid; }
.payment { margin-top: 3mm; page-break-inside: avoid; }
.payment p { font-size: 8pt; }
.prepared { margin-top: 4mm; border-top: 1px solid #cbd5e1; padding-top: 3mm; font-size: 8pt; page-break-inside: avoid; }
.sample { font-size: 8pt; font-weight: bold; letter-spacing: 1px; color: #526174; margin-bottom: 3mm; }
.preview-toolbar { max-width:182mm; margin:0 auto 8mm; padding:4mm; background:#edf1f5; font-family:sans-serif; } .preview-toolbar a,.preview-toolbar button{margin-left:4mm;}
@media print { .preview-toolbar{display:none} body { margin: 0; } .document { max-width: none; } }
@yield('design')
</style>
</head>
<body>
@if (isset($document['preview']))
<nav class="preview-toolbar"><b>{{ $document['preview']['name'] }}</b><a href="{{ $document['preview']['back'] }}">Back</a><button onclick="window.print()">Print</button><a href="{{ $document['preview']['download'] }}">Download PDF</a></nav>
@endif
<main class="document">
@if ($document['sample'])<div class="sample">SAMPLE PREVIEW · NOT A TRANSACTION</div>@endif
@yield('content')
</main></body></html>
