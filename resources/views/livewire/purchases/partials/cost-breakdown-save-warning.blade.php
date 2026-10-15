@if ($this->hasIncompleteBreakdown())
    <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        {{ __('application.cost_breakdown_is_incomplete_unit_cost_will_not_change_when_you_save_bc276722') }}
    </div>
@endif
