@extends('documents.quotations.layout')
@section('design')
.masthead{background:#eef5fc;border-top:3mm solid #225b91}.masthead td{padding:6mm;width:50%}.masthead h1{color:#225b91;font-size:29pt}.brief{margin:8mm 0}.brief td:last-child{border-left:2px solid #a5c5e3}.items th{color:#fff;background:#225b91}.summary .grand td{background:#eef5fc;color:#173f66;border-top:0;padding:4mm}
@endsection
@section('content')
<table class="masthead"><tr><td>@include('documents.quotations.partials.company')</td><td style="text-align:right"><h1>QUOTATION</h1><div class="reference">{{ $document['number'] }}</div></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
