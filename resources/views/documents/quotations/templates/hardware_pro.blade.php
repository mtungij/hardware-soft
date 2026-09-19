@extends('documents.quotations.layout')
@section('design')
.top td{padding-bottom:4mm;border-bottom:2px solid #315d55}.top h1{font-size:21pt;text-align:right}.brief{margin:3mm 0}.brief td{padding:2mm 0}.items{font-size:8.5pt}.items td{padding:3.5mm 1.5mm}.items th{background:#e8efec;border-top:2px solid #315d55}.items td:nth-child(4){font-weight:bold;background:#f3f6f5}.items .sku{font-family:dejavusansmono,monospace}.summary{width:58%}.summary .grand td{border-color:#315d55}.company-name{font-size:16pt}
@endsection
@section('content')
<table class="top"><tr><td style="width:60%">@include('documents.quotations.partials.company')</td><td><h1>QUOTATION</h1><div class="reference" style="text-align:right">{{ $document['number'] }}</div></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table><h3>Material schedule</h3>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
