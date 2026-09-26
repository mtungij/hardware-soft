@php
    $unitCostForBreakdown = (float) ($item['cost_price'] ?? 0);
    $isBreakdownComplete = $this->breakdownIsComplete($index);
@endphp
<div class="mt-1 w-36 text-xs">
    @if ($isBreakdownComplete)
        <p class="font-black text-emerald-700 dark:text-emerald-300">✓ Cost Breakdown Complete</p>
        <p class="text-slate-500">TZS {{ \App\Support\NumberFormatter::money($this->breakdownTotal($index)) }} / TZS {{ \App\Support\NumberFormatter::money($unitCostForBreakdown) }}</p>
    @endif
    <button type="button" wire:click="toggleCostBreakdown({{ $index }})" @disabled($unitCostForBreakdown <= 0) class="mt-1 text-left font-bold text-cyan-700 disabled:cursor-not-allowed disabled:text-slate-400 dark:text-cyan-300">
        {{ ($breakdown_open[$index] ?? false) ? 'Hide Breakdown' : ($isBreakdownComplete ? 'View Breakdown' : 'Cost Breakdown') }}
    </button>
    @if ($unitCostForBreakdown <= 0)
        <p class="text-slate-500">Enter Unit Cost first.</p>
    @endif
</div>
