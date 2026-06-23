@props([
    'context' => 'staff',
])

<x-card title="Jinsi ya Kuanza" description="Kamilisha hatua muhimu za kuanza kutumia mfumo.">
    <div data-hardex-checklist class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">Maendeleo</p>
            <p class="text-xs font-bold text-build-orange" data-hardex-checklist-progress>0/0 Completed</p>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
            <div class="h-full rounded-full bg-build-orange transition-all" data-hardex-checklist-bar style="width: 0%"></div>
        </div>
        <div class="space-y-2" data-hardex-checklist-items></div>
    </div>
</x-card>
