<div class="space-y-4">
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-slate-800">
                <tr>
                    <th class="px-3 py-2">{{ __('application.product_name_06bc6513') }}</th>
                    <th class="px-3 py-2">{{ __('application.sku_d59192b9') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.quantity_44f6af69') }}</th>
                    <th class="px-3 py-2">{{ __('application.selling_unit_293a9282') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.unit_price_1eea68d0') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.discount_unit_404cfa57') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.total_discount_63987053') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.tax_9be70f66') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('application.line_total_2d1a09ef') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($sale->items as $item)
                    @php
                        $discountPerUnit = (float) ($item->discount_per_unit ?? 0);
                        $totalDiscount = (float) ($item->discount_total ?? $item->discount_amount ?? 0);
                        $baseQuantity = (float) ($item->base_quantity ?: $item->quantity);
                        $showsBaseQuantity = abs($baseQuantity - (float) $item->quantity) > 0.0001 || (($unitLabel)($item) !== ($baseUnitLabel)($item));
                    @endphp
                    <tr>
                        <td class="px-3 py-2 font-bold">
                            {{ $item->product?->displayName() }}
                            @if ($item->sizeLabel())
                                <p class="text-xs font-bold text-cyan-700 dark:text-cyan-200">{{ __('application.size_86251551') }} {{ $item->sizeLabel() }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $item->product?->sku }}</td>
                        <td class="px-3 py-2 text-right">{{ \App\Support\NumberFormatter::quantity($item->quantity) }}</td>
                        <td class="px-3 py-2">{{ ($unitLabel)($item) }}</td>
                        <td class="px-3 py-2 text-right">{{ ($money)($item->unit_price) }}</td>
                        <td class="px-3 py-2 text-right">{{ ($money)($discountPerUnit) }}</td>
                        <td class="px-3 py-2 text-right">{{ ($money)($totalDiscount) }}</td>
                        <td class="px-3 py-2 text-right">{{ ($money)($item->tax_amount) }}</td>
                        <td class="px-3 py-2 text-right font-black">{{ ($money)($item->line_total) }}</td>
                    </tr>
                    @if ($showsBaseQuantity)
                        <tr>
                            <td colspan="9" class="bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 dark:bg-cyan-500/10 dark:text-cyan-100">
                                {{ __('application.selling_quantity_2fa3dea2') }} {{ \App\Support\NumberFormatter::quantity($item->quantity) }} {{ ($unitLabel)($item) }}
                                {{ __('application.base_quantity_deducted_565e2f1b') }} {{ \App\Support\NumberFormatter::quantity($baseQuantity) }} {{ ($baseUnitLabel)($item) }}
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-2 text-sm sm:grid-cols-3 xl:grid-cols-6">
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.subtotal_97f7359e') }}</p><p class="font-black">{{ ($money)($sale->subtotal) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.discount_b524936d') }}</p><p class="font-black">{{ ($money)($sale->discount_amount) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.tax_9be70f66') }}</p><p class="font-black">{{ ($money)($sale->tax_amount) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.grand_total_91faafb1') }}</p><p class="font-black">{{ ($money)($sale->total_amount) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.amount_paid_a2dc17b5') }}</p><p class="font-black">{{ ($money)($sale->paid_amount) }}</p></div>
        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><p class="text-slate-500">{{ __('application.outstanding_f8ee57ec') }}</p><p class="font-black text-red-600">{{ ($money)($sale->balance_amount) }}</p></div>
    </div>
</div>
