<table class="metadata">
<tr><td>Quotation No.</td><td><b>{{ $document['number'] }}</b></td></tr>
<tr><td>Date</td><td>{{ $document['date'] }}</td></tr>
@if ($document['valid_until'])<tr><td>Valid Until</td><td>{{ $document['valid_until'] }}</td></tr>@endif
@if ($document['branch'])<tr><td>Branch</td><td>{{ $document['branch'] }}</td></tr>@endif
</table>
