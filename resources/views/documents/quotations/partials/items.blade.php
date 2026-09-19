<div class="currency">Amounts in {{ $document['currency'] }}</div>
<table class="items">
<thead><tr><th style="width:4%">#</th><th style="width:33%">Product</th><th style="width:9%">Unit</th><th style="width:9%" class="numeric">Qty</th><th style="width:15%" class="numeric">Unit Price</th><th style="width:13%" class="numeric">Discount</th><th style="width:17%" class="numeric">Line Total</th></tr></thead>
<tbody>
@foreach ($document['items'] as $item)
<tr><td>{{ $loop->iteration }}</td><td><b>{{ $item['product'] }}</b>@if ($item['sku'])<div class="sku">{{ $item['sku'] }}</div>@endif</td><td>{{ $item['unit'] }}</td><td class="numeric">{{ $item['quantity'] }}</td><td class="numeric">{{ $item['price'] }}</td><td class="numeric">{{ $item['discount'] }}</td><td class="numeric">{{ $item['total'] }}</td></tr>
@endforeach
</tbody></table>
