@extends('documents.quotations.layout')
@section('design')
.company-name{color:#b45309}.items th,.items td{border:1px solid #b5beca}.title{border-bottom:2px solid #243247;padding-bottom:3mm}.brief td{padding-left:0}
@endsection
@section('content')
@include('documents.quotations.partials.company')<h1 class="title">QUOTATION</h1><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')
@include('documents.quotations.partials.totals')
@include('documents.quotations.partials.terms')
<div class="closing">@include('documents.quotations.partials.payments')
@include('documents.quotations.partials.prepared')</div>
@endsection
