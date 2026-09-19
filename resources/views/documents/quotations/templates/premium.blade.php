@extends('documents.quotations.layout')
@section('design')
.premium-head{border-bottom:1px solid #b39b73}.premium-head td{padding:5mm 0}.premium-head .title-cell{border-left:2mm solid #b39b73;padding-left:6mm;width:42%}.premium-head h1{font-family:dejavuserif,serif;font-size:27pt;letter-spacing:0}.company-name{font-family:dejavuserif,serif;font-size:22pt}.brief{margin:7mm 0}.brief td{padding:0 4mm 0 0}.items th{background:#f4f1eb;border-top:1px solid #b39b73;border-bottom:1px solid #b39b73}.items td{padding:3.5mm 1.5mm}.summary{background:#f7f5f1;width:57%}.summary td{padding:2.5mm}.summary .grand td{border-top:2px solid #b39b73;font-family:dejavuserif,serif;font-size:12pt}.prepared{border-color:#b39b73;text-align:right}.section h3{color:#554b3c}
@endsection
@section('content')
<table class="premium-head"><tr><td>@include('documents.quotations.partials.company')</td><td class="title-cell"><h1>Quotation</h1><div class="reference">{{ $document['number'] }}</div></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
