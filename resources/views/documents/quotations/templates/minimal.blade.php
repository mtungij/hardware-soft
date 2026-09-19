@extends('documents.quotations.layout')
@section('design')
body{font-size:9pt}.company-name{font-size:16pt;font-weight:normal}.top td{width:50%}.top h1{font-size:18pt;font-weight:normal;text-align:right}.brief{margin:12mm 0 8mm}.brief td{padding:0 4mm 0 0}.items th{background:white;border-bottom:1px solid #243247}.items td{padding:4mm 1.5mm}.summary td{border:0;padding:2mm}.summary .grand td{border-top:1px solid #243247}.prepared{margin-top:10mm}
@endsection
@section('content')
<table class="top"><tr><td>@include('documents.quotations.partials.company')</td><td><h1>Quotation</h1></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
