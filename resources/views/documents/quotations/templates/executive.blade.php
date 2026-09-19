@extends('documents.quotations.layout')
@section('design')
.executive-head{border-top:3mm solid #293d55;border-bottom:1px solid #293d55;padding:5mm 0}.executive-head td{width:50%;padding:4mm 0}.executive-head h1{font-size:24pt;text-align:right}.company-name{font-size:21pt}.brief td{padding:4mm 0}.items th{background:#293d55;color:white}.summary{border-top:1mm solid #293d55;width:55%}.summary .grand td{border-top:1px solid #293d55;background:#eef1f5}.prepared{border-top:2px solid #293d55;text-align:right;padding-top:4mm}.section h3{letter-spacing:1.5px}
@endsection
@section('content')
<table class="executive-head"><tr><td>@include('documents.quotations.partials.company')</td><td><h1>QUOTATION</h1><p class="muted" style="text-align:right">Prepared for your consideration</p></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
