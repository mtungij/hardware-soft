@extends('documents.quotations.layout')
@section('design')
body{font-size:8pt;line-height:1.3}.company-name{font-size:13pt}.contact{font-size:7pt;margin-top:1mm}.logo{max-height:10mm;max-width:28mm}.compact-head td{width:50%;padding-bottom:2mm}.compact-head h1{font-size:17pt;margin-bottom:2mm}.brief{margin:2mm 0}.brief td{padding:2mm 0}.customer-name{font-size:10pt}.items{margin-top:1mm;font-size:7.5pt}.items td{padding:1.5mm 1mm}.items th{padding:1.5mm 1mm;font-size:7pt}.items .sku{margin:0;font-size:6.5pt}.summary{margin-top:3mm}.summary td{padding:1mm}.summary .grand td{font-size:10pt}.section{margin-top:3mm}.prepared{margin-top:4mm}.currency{margin-top:1mm}
@endsection
@section('content')
<table class="compact-head"><tr><td>@include('documents.quotations.partials.company')</td><td><h1>QUOTATION</h1>@include('documents.quotations.partials.metadata')</td></tr></table><div class="brief">@include('documents.quotations.partials.customer')</div>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
