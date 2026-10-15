<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Services\CustomerMaterialAccountService;
use Illuminate\Support\Str;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');
state(['customer_id' => '', 'branch_id' => (\App\Support\BranchAccess::restricted() ? (string) auth()->user()->branch_id : ''), 'project_name' => '', 'description' => '', 'project_location' => '', 'status' => 'active', 'lines' => []]);
$newLine = fn () => ['key' => (string) Str::uuid(), 'product_id' => '', 'product_unit_conversion_id' => '', 'planned_quantity' => 1, 'agreed_unit_price' => ''];
mount(function () { $this->branch_id = (string) (auth()->user()->branch_id ?: Branch::value('id')); $this->lines = [$this->newLine()]; });
$addLine = function () { $this->lines[] = $this->newLine(); };
$removeLine = function (int $index) { unset($this->lines[$index]); $this->lines = array_values($this->lines); if ($this->lines === []) $this->lines[] = $this->newLine(); };
$save = function (CustomerMaterialAccountService $service) {
    $data = $this->validate([
        'customer_id' => ['required', 'exists:customers,id'], 'branch_id' => ['required', 'exists:branches,id'],
        'project_name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
        'project_location' => ['nullable', 'string', 'max:255'], 'status' => ['required', 'in:draft,active'],
        'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'exists:products,id'],
        'lines.*.product_unit_conversion_id' => ['nullable', 'exists:product_unit_conversions,id'],
        'lines.*.planned_quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.agreed_unit_price' => ['required', 'numeric', 'min:0'],
    ]);
    $account = $service->create([...$data, 'company_id' => auth()->user()->company_id], $data['lines'], auth()->id());
    session()->flash('success', __('application.customer_material_account_created_no_stock_was_moved_b2291b51'));
    $this->redirectRoute('customer-material-accounts.show', $account, navigate: true);
};
?>

<div>
    <x-page-header title="{{ __('application.create_material_account_297e451a') }}" description="{{ __('application.agree_a_priced_material_plan_planning_does_not_reserve_or_move_stock_44bfef02') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Material Accounts' => route('customer-material-accounts.index'), 'Create' => null]" />
    <form wire:submit="save" class="space-y-5">
        <x-card title="{{ __('application.project_f6f4da8d') }}">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="text-sm font-bold">{{ __('application.customer_0e85749a') }}<select wire:model="customer_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.select_customer_6817176b') }}</option>@foreach(Customer::where(fn($q) => $q->where('is_system_customer', false)->orWhereNull('is_system_customer'))->orderBy('name')->get() as $customer)<option value="{{ $customer->id }}">{{ $customer->displayName() }} — {{ $customer->phone }}</option>@endforeach</select>@error('customer_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>
                <label class="text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model="branch_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())>@foreach(Branch::orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
                <x-form-input label="{{ __('application.project_name_5f950764') }}" name="project_name" wire:model="project_name" required />
                <x-form-input label="{{ __('application.project_location_c7bee4bc') }}" name="project_location" wire:model="project_location" />
                <label class="text-sm font-bold">{{ __('application.initial_status_70dfd71d') }}<select wire:model="status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="active">{{ __('application.active_a733b809') }}</option><option value="draft">{{ __('application.draft_23d33e22') }}</option></select></label>
            </div>
            <label class="mt-4 block text-sm font-bold">{{ __('application.description_notes_60cdf079') }}<textarea wire:model="description" class="mt-1 min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></textarea></label>
        </x-card>
        <x-card title="{{ __('application.agreed_material_plan_a8e4355c') }}">
            <x-table :headers="[__('application.product_dd3b86d1'), __('application.transaction_unit_19ffe8aa'), __('application.planned_quantity_45230c83'), __('application.agreed_unit_price_eb5d7ce3'), __('application.planned_total_44ba0257'), '']">
                @foreach($lines as $index => $row)
                    @php $product = $row['product_id'] ? Product::with(['unit', 'unitConversions.unit'])->find($row['product_id']) : null; @endphp
                    <tr>
                        <td class="px-4 py-3"><select wire:model.live="lines.{{ $index }}.product_id" class="w-64 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="">{{ __('application.select_product_596d90e5') }}</option>@foreach(Product::with('size')->where('status','active')->orderBy('name')->get() as $option)<option value="{{ $option->id }}">{{ $option->displayNameWithSize() }}</option>@endforeach</select>@error("lines.$index.product_id")<p class="text-xs text-red-600">{{ $message }}</p>@enderror</td>
                        <td class="px-4 py-3"><select wire:model="lines.{{ $index }}.product_unit_conversion_id" class="w-48 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"><option value="">{{ $product?->unit?->name ?? __('application.base_unit_e1532ca3') }}</option>@foreach($product?->unitConversions?->where('active', true)->where('can_sell', true) ?? [] as $conversion)<option value="{{ $conversion->id }}">{{ $conversion->unit->name }} (×{{ $conversion->conversion_factor }})</option>@endforeach</select></td>
                        <td class="px-4 py-3"><input wire:model.live="lines.{{ $index }}.planned_quantity" type="number" min="0.0001" step="any" class="w-32 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3"><input wire:model.live="lines.{{ $index }}.agreed_unit_price" type="number" min="0" step="0.01" class="w-40 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700 dark:bg-navy-950"></td>
                        <td class="px-4 py-3 text-right font-black">TZS {{ \App\Support\NumberFormatter::money((float)($row['planned_quantity'] ?: 0) * (float)($row['agreed_unit_price'] ?: 0)) }}</td>
                        <td class="px-4 py-3"><button type="button" wire:click="removeLine({{ $index }})" class="font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></td>
                    </tr>
                @endforeach
            </x-table>
            <div class="mt-4 flex items-center justify-between"><button type="button" wire:click="addLine" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">{{ __('application.add_material_5087f91e') }}</button><p class="text-lg font-black">{{ __('application.project_total_tzs_15d16164') }} {{ \App\Support\NumberFormatter::money(collect($lines)->sum(fn($row) => (float)($row['planned_quantity'] ?: 0) * (float)($row['agreed_unit_price'] ?: 0))) }}</p></div>
        </x-card>
        <div class="flex gap-2"><button class="rounded-lg bg-build-orange px-5 py-2.5 text-sm font-bold text-white">{{ __('application.create_account_eff4fd86') }}</button><a wire:navigate href="{{ route('customer-material-accounts.index') }}" class="rounded-lg border border-slate-200 px-5 py-2.5 text-sm font-bold dark:border-slate-700">{{ __('application.cancel_77dfd213') }}</a></div>
    </form>
</div>
