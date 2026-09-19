@extends('documents.quotations.layout')
@section('design')
.project-title{border-left:4mm solid #d59721;padding-left:5mm;margin-bottom:6mm}.project-title h1{font-size:28pt}.brief{border-top:2px solid #3d403c;border-bottom:2px solid #3d403c}.brief td{background:#f5f3ed}.items th{background:#3d403c;color:#fff;border-bottom:2px solid #d59721}.items td{border:1px solid #c7c9c2}.summary{width:60%}.summary .grand td{background:#f5f0e3}.section h3{border-left:2mm solid #d59721;padding-left:2mm}.prepared{border-top:2px solid #3d403c}
@endsection
@section('content')
<table><tr><td style="width:55%">@include('documents.quotations.partials.company')</td><td><div class="project-title"><h1>QUOTATION</h1><p>Materials &amp; project supply</p></div></td></tr></table><table class="brief"><tr><td>@include('documents.quotations.partials.customer')</td><td>@include('documents.quotations.partials.metadata')</td></tr></table>@include('documents.quotations.partials.items')@include('documents.quotations.partials.totals', ['summaryHeading' => 'Commercial summary · delivery & charges'])@include('documents.quotations.partials.terms')<div class="closing">@include('documents.quotations.partials.payments')@include('documents.quotations.partials.prepared')</div>
@endsection
