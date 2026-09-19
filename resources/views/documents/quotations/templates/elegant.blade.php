@extends('documents.quotations.layout')
@section('design')
.title{margin:4mm 0}.brief{margin:4mm 0}.prepared{margin-top:3mm;padding-top:2mm}
body{font-family:dejavuserif,serif;color:#3e3838}.letterhead{text-align:center;border-top:1px solid #938074;border-bottom:1px solid #938074;padding:5mm}.company-name{font-size:21pt;font-weight:normal}.title{text-align:center;font-size:23pt;font-weight:normal;letter-spacing:3px}.brief td{padding:4mm 0}.items th{background:#f5f2ef;border-top:1px solid #938074;font-family:dejavusans,sans-serif}.items td{border-bottom:1px solid #ddd5ce}.summary .grand td{border-color:#938074}.prepared{text-align:center}
@endsection
@section('content')
<div class="letterhead">@include('documents.quotations.partials.company')</div><h1 class="title">QUOTATION</h1><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
