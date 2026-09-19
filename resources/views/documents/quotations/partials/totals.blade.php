<table class="summary">
@if (isset($summaryHeading))<tr><td colspan="2"><h3>{{ $summaryHeading }}</h3></td></tr>@endif
@foreach ($document['totals'] as $total)<tr><td>{{ $total['label'] }}</td><td class="amount">{{ $total['value'] }}</td></tr>@endforeach
@foreach ($document['charges'] as $charge)<tr><td>{{ $charge['label'] }}@if ($charge['description'])<div class="muted" style="font-size:7.5pt">{{ $charge['description'] }}</div>@endif</td><td class="amount">{{ $charge['value'] }}</td></tr>@endforeach
<tr class="grand"><td>Grand Total</td><td class="amount">{{ $document['grand_total'] }}</td></tr>
</table>
