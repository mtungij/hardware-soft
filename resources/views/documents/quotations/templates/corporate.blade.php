@extends('documents.quotations.layout')
@section('design')
.identity{border-bottom:1mm solid #253e4c;padding-bottom:5mm}.company-name{font-size:23pt}.brief td{width:33%;border-right:1px solid #cbd5e1;padding:4mm}.brief .metadata td{width:auto;border:0;padding:1mm 0}.brief h1{font-size:19pt}.items th{background:#253e4c;color:white}.summary{border:1px solid #b8c5cc}.summary .grand td{background:#edf2f3}.prepared{border-top:2px solid #253e4c}
@endsection
@section('content')
<div class="identity">@include('documents.quotations.partials.company')</div><table class="brief"><tr><td><h1>QUOTATION</h1><p class="muted">Commercial proposal</p></td><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
