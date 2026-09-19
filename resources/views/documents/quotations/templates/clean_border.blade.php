@extends('documents.quotations.layout')
@section('design')
.outer-head{border:1px solid #5c7180}.outer-head td{padding:5mm}.outer-head h1{font-size:23pt}.brief td{border:1px solid #7e8e9a;padding:4mm}.brief{margin:4mm 0}.items th,.items td{border:1px solid #9aabb7}.items th{background:#edf2f5}.summary{border:1px solid #7e8e9a}.summary td{border-bottom:1px solid #cbd5e1}.summary .grand td{background:#edf2f5}.section{border-left:1px solid #7e8e9a;padding-left:3mm}
@endsection
@section('content')
<table class="outer-head"><tr><td style="width:60%">@include('documents.quotations.partials.company')</td><td><h1>QUOTATION</h1></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
