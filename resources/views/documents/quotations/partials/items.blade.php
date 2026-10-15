<div class="currency">{{ __('application.amounts_in_dce0dfdf') }} {{ $document['currency'] }}</div>
<table class="items">
<thead><tr><th style="width:4%">#</th><th style="width:33%">{{ __('application.product_dd3b86d1') }}</th><th style="width:9%">{{ __('application.unit_f6b935ab') }}</th><th style="width:9%" class="numeric">{{ __('application.qty_1e5ff9e5') }}</th><th style="width:15%" class="numeric">{{ __('application.unit_price_1eea68d0') }}</th><th style="width:13%" class="numeric">{{ __('application.discount_b524936d') }}</th><th style="width:17%" class="numeric">{{ __('application.line_total_2d1a09ef') }}</th></tr></thead>
<tbody>
@foreach ($document['items'] as $item)
<tr><td>{{ $loop->iteration }}</td><td><b>{{ $item['product'] }}</b>@if ($item['sku'])<div class="sku">{{ $item['sku'] }}</div>@endif</td><td>{{ $item['unit'] }}</td><td class="numeric">{{ $item['quantity'] }}</td><td class="numeric">{{ $item['price'] }}</td><td class="numeric">{{ $item['discount'] }}</td><td class="numeric">{{ $item['total'] }}</td></tr>
@endforeach
</tbody></table>
