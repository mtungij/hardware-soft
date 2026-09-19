@extends('documents.quotations.layout')
@section('design')
.title{margin:4mm 0}.brief{margin:3mm 0}.prepared{margin-top:3mm;padding-top:2mm}
.banner{border-bottom:3mm solid #bd4c25;padding:3mm 0}.banner h1{font-size:39pt;letter-spacing:2px}.reference-band{background:#f6eee9;padding:3mm;font-size:14pt;font-weight:bold;margin-bottom:3mm}.brief td{padding:3mm 0}.company-name{font-size:16pt}.items th{background:#293444;color:white;padding:3mm 1.5mm}.summary .grand td{font-size:13pt;border-top:3px solid #bd4c25}
@endsection
@section('content')
<div class="banner"><h1>QUOTATION</h1></div><div class="reference-band">{{ $document['number'] }}</div><table><tr><td style="width:55%">@include('documents.quotations.partials.company')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table><div class="brief">@include('documents.quotations.partials.customer')</div>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
