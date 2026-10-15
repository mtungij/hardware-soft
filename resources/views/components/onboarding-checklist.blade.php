@props([
    'context' => 'staff',
])

<x-card title="{{ __('application.getting_started_010b85ad') }}" description="{{ __('application.complete_the_essential_steps_to_start_using_the_system_8e0c52fd') }}">
    <div data-hardex-checklist class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('application.progress_1b90271d') }}</p>
            <p class="text-xs font-bold text-build-orange" data-hardex-checklist-progress>{{ __('application.0_0_completed_671af166') }}</p>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
            <div class="h-full rounded-full bg-build-orange transition-all" data-hardex-checklist-bar style="width: 0%"></div>
        </div>
        <div class="space-y-2" data-hardex-checklist-items></div>
    </div>
</x-card>
