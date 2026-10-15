<table class="metadata">
<tr><td>{{ __('application.quotation_no_b1f2ab01') }}</td><td><b>{{ $document['number'] }}</b></td></tr>
<tr><td>{{ __('application.date_eb9a4bc1') }}</td><td>{{ $document['date'] }}</td></tr>
@if ($document['valid_until'])<tr><td>{{ __('application.valid_until_a144230d') }}</td><td>{{ $document['valid_until'] }}</td></tr>@endif
@if ($document['branch'])<tr><td>{{ __('application.branch_1627510b') }}</td><td>{{ $document['branch'] }}</td></tr>@endif
</table>
