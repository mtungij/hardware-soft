@php
    $allocated = $payment->allocations->sum('allocated_amount');
    $unallocated = max(0, (float) $payment->amount - (float) $allocated);
@endphp

<div class="space-y-1 text-xs">
    @forelse ($payment->allocations as $allocation)
        <div class="flex justify-between gap-3">
            <span class="font-mono">{{ $allocation->sale?->sale_number }}</span>
            <span class="font-bold">{{ ($money)($allocation->allocated_amount) }}</span>
        </div>
    @empty
        <span class="text-slate-500">No sale allocation recorded.</span>
    @endforelse

    @if ($unallocated > 0)
        <div class="flex justify-between gap-3 text-amber-700 dark:text-amber-300">
            <span>Unallocated</span>
            <span class="font-bold">{{ ($money)($unallocated) }}</span>
        </div>
    @endif
</div>
