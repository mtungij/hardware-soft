<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyWhatsAppSetting;
use App\Models\User;
use App\Models\WhatsAppRecipient;
use App\Models\WhatsAppTemplate;
use App\Services\Gowa;
use App\Services\WhatsAppAuditService;
use App\Services\WhatsAppNotificationService;
use App\Services\WhatsAppTemplateService;
use App\Support\WhatsAppPhone;
use Illuminate\Validation\Rule;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'companyId' => null,
    'enabled' => false,
    'sending_paused' => false,
    'device_id' => '',
    'timezone' => 'Africa/Dar_es_Salaam',
    'daily_summary_time' => '20:00',
    'attach_daily_summary_pdf' => false,
    'debt_reminders_enabled' => false,
    'debt_due_tomorrow_enabled' => true,
    'debt_due_today_enabled' => true,
    'debt_overdue_enabled' => true,
    'debt_reminder_time' => '08:00',
    'debt_overdue_interval_days' => 3,
    'attach_debt_summary_pdf' => false,
    'quiet_hours_start' => '',
    'quiet_hours_end' => '',
    'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    'minimum_send_interval_seconds' => 15,
    'maximum_messages_per_minute' => 3,
    'maximum_messages_per_hour' => 60,
    'low_stock_cooldown_hours' => 24,
    'attach_stock_alert_pdf' => true,
    'test_recipient' => '',
    'last_device_state' => 'unknown',
    'last_checked_at' => null,
    'recipient_name' => '',
    'recipient_phone' => '',
    'recipient_user_id' => '',
    'recipient_branch_id' => '',
    'recipient_scope' => 'company',
    'recipient_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES,
    'template_bodies' => [],
]);

mount(function (): void {
    abort_unless(auth()->user()->can('whatsapp.view_settings'), 403);
    $company = Company::current();
    abort_unless($company, 404);
    $this->companyId = $company->id;
    app(WhatsAppTemplateService::class)->seedDefaults($company);
    $this->template_bodies = WhatsAppTemplate::withoutGlobalScopes()->where('company_id', $company->id)->pluck('body', 'key')->all();
    $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->firstOrCreate(
        ['company_id' => $company->id],
        ['timezone' => $company->timezone ?: 'Africa/Dar_es_Salaam', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES]
    );

    foreach (['enabled', 'sending_paused', 'device_id', 'timezone', 'daily_summary_time', 'attach_daily_summary_pdf', 'debt_reminders_enabled', 'debt_due_tomorrow_enabled', 'debt_due_today_enabled', 'debt_overdue_enabled', 'debt_reminder_time', 'debt_overdue_interval_days', 'attach_debt_summary_pdf', 'quiet_hours_start', 'quiet_hours_end', 'enabled_categories', 'minimum_send_interval_seconds', 'maximum_messages_per_minute', 'maximum_messages_per_hour', 'low_stock_cooldown_hours', 'attach_stock_alert_pdf', 'test_recipient', 'last_device_state', 'last_checked_at'] as $field) {
        $value = $setting->{$field};
        if (in_array($field, ['daily_summary_time', 'debt_reminder_time', 'quiet_hours_start', 'quiet_hours_end'], true) && is_string($value)) {
            $value = substr($value, 0, 5);
        }
        $this->{$field} = $value instanceof DateTimeInterface ? $value->format('d M Y H:i') : ($value ?? $this->{$field});
    }
});

$categories = fn (): array => [
    'daily_summary' => 'Daily Management Summary',
    'stock_alerts' => 'Low / Out of Stock',
    'sales' => 'Sales',
    'security' => 'Cancellation / Security',
    'customer_payments' => 'Customer Payments',
    'customer_debt' => 'Customer Debt Reminders',
    'purchases' => 'Purchases / Goods Received',
    'customer_materials' => 'Customer Material Accounts',
    'production' => 'Production / Curing',
    'customer_requests' => 'Customer Request Alerts',
    'quotations' => 'Quotation Sent to Customer',
    'quotation_acceptance' => 'Quotation Accepted',
        'customer_invoices' => 'Final Invoice to Customer',
        'customer_portal' => 'Customer Portal Credentials',
];

$save = function (Gowa $gowa, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $data = $this->validate([
        'enabled' => ['boolean'], 'sending_paused' => ['boolean'],
        'device_id' => ['nullable', 'string', 'max:255'],
        'timezone' => ['required', 'timezone'], 'daily_summary_time' => ['required', 'date_format:H:i'],
        'attach_daily_summary_pdf' => ['boolean'],
        'debt_reminders_enabled' => ['boolean'],
        'debt_due_tomorrow_enabled' => ['boolean'],
        'debt_due_today_enabled' => ['boolean'],
        'debt_overdue_enabled' => ['boolean'],
        'debt_reminder_time' => ['required', 'date_format:H:i'],
        'debt_overdue_interval_days' => ['required', 'integer', 'min:1', 'max:365'],
        'attach_debt_summary_pdf' => ['boolean'],
        'quiet_hours_start' => ['nullable', 'date_format:H:i'], 'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        'enabled_categories' => ['array'], 'enabled_categories.*' => [Rule::in(array_keys($this->categories()))],
        'minimum_send_interval_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
        'maximum_messages_per_minute' => ['required', 'integer', 'min:1', 'max:60'],
        'maximum_messages_per_hour' => ['required', 'integer', 'min:1', 'max:1000'],
        'low_stock_cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
        'attach_stock_alert_pdf' => ['boolean'],
        'test_recipient' => ['nullable', 'string', 'max:30'],
    ]);

    $data['device_id'] = filled($data['device_id']) ? trim($data['device_id']) : null;
    $data['quiet_hours_start'] = $data['quiet_hours_start'] ?: null;
    $data['quiet_hours_end'] = $data['quiet_hours_end'] ?: null;
    try {
        $data['test_recipient'] = filled($data['test_recipient']) ? WhatsAppPhone::normalize($data['test_recipient']) : null;
    } catch (Throwable $exception) {
        $this->addError('test_recipient', $exception->getMessage());

        return;
    }

    if ($data['enabled']) {
        if (blank($data['device_id'])) {
            $this->addError('device_id', 'A Device ID is required before enabling WhatsApp notifications.');

            return;
        }

        try {
            $state = $gowa->deviceState($gowa->deviceStatus($data['device_id']));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('device_id', 'Could not verify the WhatsApp device. Check the Device ID and GOWA service.');

            return;
        }
        if ($state !== 'logged_in') {
            $this->addError('device_id', 'WhatsApp device is not connected. Re-link the device before enabling notifications.');

            return;
        }
        $data['last_device_state'] = $state;
        $data['last_checked_at'] = now();
    }

    $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->first();
    $before = $setting?->only(array_keys($data));
    $setting = CompanyWhatsAppSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $this->companyId], $data);
    $audit->record($this->companyId, 'settings_updated', $before, $setting->only(array_keys($data)));
    $this->last_device_state = $data['last_device_state'] ?? $this->last_device_state;
    $this->last_checked_at = isset($data['last_checked_at']) ? now()->format('d M Y H:i') : $this->last_checked_at;
    session()->flash('success', 'WhatsApp notification settings saved.');
};

$testConnection = function (Gowa $gowa): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $this->validate(['device_id' => ['required', 'string', 'max:255']]);
    try {
        $state = $gowa->deviceState($gowa->deviceStatus(trim($this->device_id)));
        CompanyWhatsAppSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->update(['last_device_state' => $state, 'last_checked_at' => now()]);
        $this->last_device_state = $state;
        $this->last_checked_at = now()->format('d M Y H:i');
        session()->flash($state === 'logged_in' ? 'success' : 'error', $state === 'logged_in' ? 'WhatsApp device is connected and logged in.' : 'WhatsApp device is not connected. Re-link it before enabling notifications.');
    } catch (Throwable $exception) {
        report($exception);
        $this->last_device_state = 'unknown';
        session()->flash('error', 'Could not verify the WhatsApp device. Check the Device ID and GOWA service.');
    }
};

$testMessage = function (WhatsAppNotificationService $notifications): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $this->validate(['test_recipient' => ['required', 'string', 'max:30']]);
    $company = Company::query()->findOrFail($this->companyId);
    $notification = $notifications->queueTest($company, $this->test_recipient, "HARDEX WhatsApp test\n{$company->company_name}\n".now()->format('d M Y H:i'));
    session()->flash($notification->status === 'queued' ? 'success' : 'error', $notification->status === 'queued' ? 'Test message queued. Check the notification log for delivery status.' : $notification->failure_reason);
};

$addRecipient = function (WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_recipients'), 403);
    $data = $this->validate([
        'recipient_name' => ['required', 'string', 'max:255'],
        'recipient_phone' => ['required', 'string', 'max:30'],
        'recipient_user_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $this->companyId)],
        'recipient_scope' => ['required', Rule::in(['company', 'branch'])],
        'recipient_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $this->companyId)],
        'recipient_categories' => ['required', 'array', 'min:1'],
        'recipient_categories.*' => [Rule::in(array_keys($this->categories()))],
    ]);
    if ($data['recipient_scope'] === 'branch' && blank($data['recipient_branch_id'])) {
        $this->addError('recipient_branch_id', 'Select a branch for a branch-scoped recipient.');

        return;
    }

    try {
        $phone = WhatsAppPhone::normalize($data['recipient_phone']);
    } catch (Throwable $exception) {
        $this->addError('recipient_phone', $exception->getMessage());

        return;
    }

    $recipient = WhatsAppRecipient::withoutGlobalScopes()->create([
        'company_id' => $this->companyId, 'name' => $data['recipient_name'],
        'phone' => $phone,
        'user_id' => $data['recipient_user_id'] ?: null,
        'scope' => $data['recipient_scope'],
        'branch_id' => $data['recipient_scope'] === 'branch' ? $data['recipient_branch_id'] : null,
        'categories' => $data['recipient_categories'], 'active' => true,
    ]);
    $audit->record($this->companyId, 'recipient_added', null, $recipient->only(['id', 'name', 'phone', 'user_id', 'branch_id', 'scope', 'categories', 'active']));
    $this->reset('recipient_name', 'recipient_phone', 'recipient_user_id', 'recipient_branch_id');
    session()->flash('success', 'WhatsApp recipient added.');
};

$toggleRecipient = function (int $id, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_recipients'), 403);
    $recipient = WhatsAppRecipient::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($id);
    $before = $recipient->only(['id', 'active']);
    $recipient->update(['active' => ! $recipient->active]);
    $audit->record($this->companyId, 'recipient_status_changed', $before, $recipient->only(['id', 'active']));
};

$deleteRecipient = function (int $id, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_recipients'), 403);
    $recipient = WhatsAppRecipient::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($id);
    $before = $recipient->only(['id', 'name', 'phone', 'user_id', 'branch_id', 'scope', 'categories', 'active']);
    $recipient->delete();
    $audit->record($this->companyId, 'recipient_removed', $before);
};

$saveTemplates = function (WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_templates'), 403);
    $this->validate(['template_bodies' => ['required', 'array'], 'template_bodies.*' => ['required', 'string', 'max:4000']]);
    foreach ($this->template_bodies as $key => $body) {
        WhatsAppTemplate::withoutGlobalScopes()->where('company_id', $this->companyId)->where('key', $key)->update(['body' => $body]);
    }
    $audit->record($this->companyId, 'templates_updated', null, ['template_keys' => array_keys($this->template_bodies)]);
    session()->flash('success', 'WhatsApp templates saved.');
};

?>

<div>
    <x-page-header title="WhatsApp Notifications" description="Configure this company's linked device, responsible delivery controls, recipients, and categories." :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'WhatsApp' => null]" />

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="mb-4 rounded-xl bg-red-50 p-4 text-sm font-bold text-red-700">{{ session('error') }}</div> @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card class="xl:col-span-2">
            <form wire:submit="save" class="space-y-5">
                <div class="flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="enabled" class="rounded text-build-orange"> Enable notifications</label>
                    <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="sending_paused" class="rounded text-build-orange"> Pause sending</label>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-input label="Company Device ID" name="device_id" wire:model="device_id" />
                    <div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <div class="font-black">Device: {{ str($last_device_state ?: 'unknown')->replace('_', ' ')->title() }}</div>
                        <div class="text-xs text-slate-500">Last checked: {{ $last_checked_at ?: 'Never' }}</div>
                        <button type="button" wire:click="testConnection" wire:loading.attr="disabled" class="mt-2 rounded-lg border px-3 py-1.5 text-xs font-black">Test Connection</button>
                    </div>
                    <x-form-input label="Timezone" name="timezone" wire:model="timezone" />
                    <x-form-input label="Daily Summary Time" name="daily_summary_time" type="time" wire:model="daily_summary_time" />
                    <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_daily_summary_pdf" class="rounded text-build-orange"> Attach permission-filtered daily PDF</label>
                    <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_reminders_enabled" class="rounded text-build-orange"> Enable transactional debt reminders</label>
                    <x-form-input label="Debt Reminder Time" name="debt_reminder_time" type="time" wire:model="debt_reminder_time" />
                    <x-form-input label="Overdue Reminder Interval (Days)" name="debt_overdue_interval_days" type="number" wire:model="debt_overdue_interval_days" />
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_due_tomorrow_enabled" class="rounded text-build-orange"> Due tomorrow</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_due_today_enabled" class="rounded text-build-orange"> Due today</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_overdue_enabled" class="rounded text-build-orange"> Overdue</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_debt_summary_pdf" class="rounded text-build-orange"> Attach scoped debtor PDF to management summary</label>
                    <x-form-input label="Quiet Hours Start" name="quiet_hours_start" type="time" wire:model="quiet_hours_start" />
                    <x-form-input label="Quiet Hours End" name="quiet_hours_end" type="time" wire:model="quiet_hours_end" />
                    <x-form-input label="Minimum Seconds Between Messages" name="minimum_send_interval_seconds" type="number" wire:model="minimum_send_interval_seconds" />
                    <x-form-input label="Maximum Messages / Minute" name="maximum_messages_per_minute" type="number" wire:model="maximum_messages_per_minute" />
                    <x-form-input label="Maximum Messages / Hour" name="maximum_messages_per_hour" type="number" wire:model="maximum_messages_per_hour" />
                    <x-form-input label="Low Stock Cooldown (Hours)" name="low_stock_cooldown_hours" type="number" wire:model="low_stock_cooldown_hours" />
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_stock_alert_pdf" class="rounded text-build-orange"> Attach Low / Out of Stock PDF</label>
                </div>
                <div><div class="mb-2 text-sm font-black">Enabled Categories</div><div class="grid gap-2 md:grid-cols-2">@foreach($this->categories() as $key => $label)<label class="flex gap-2 text-sm"><input type="checkbox" value="{{ $key }}" wire:model="enabled_categories" class="rounded text-build-orange"> {{ $label }}</label>@endforeach</div></div>
                <div class="flex gap-3"><button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">Save Settings</button><a href="{{ route('settings.whatsapp.logs') }}" class="rounded-xl border px-4 py-2.5 text-sm font-black">Notification Log</a></div>
            </form>
            <div class="mt-6 border-t pt-5 dark:border-slate-700"><div class="flex gap-3"><div class="flex-1"><x-form-input label="Test Recipient" name="test_recipient" wire:model="test_recipient" placeholder="0764xxxxxx" /></div><button type="button" wire:click="testMessage" class="self-end rounded-xl border px-4 py-2.5 text-sm font-black">Queue Test Message</button></div></div>
        </x-card>

        <x-card>
            <h2 class="text-lg font-black">Recipients</h2>
            <form wire:submit="addRecipient" class="mt-4 space-y-3">
                <x-form-input label="Name / Role" name="recipient_name" wire:model="recipient_name" />
                <x-form-input label="Phone" name="recipient_phone" wire:model="recipient_phone" />
                <label class="block text-sm font-bold">Staff User<select wire:model="recipient_user_id" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Phone-only recipient</option>@foreach(User::withoutGlobalScopes()->where('company_id',$companyId)->where('status','active')->orderBy('name')->get() as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
                <label class="block text-sm font-bold">Scope<select wire:model.live="recipient_scope" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="company">Company</option><option value="branch">Branch</option></select></label>
                @if($recipient_scope === 'branch')<label class="block text-sm font-bold">Branch<select wire:model="recipient_branch_id" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">Select branch</option>@foreach(Branch::withoutGlobalScopes()->where('company_id',$companyId)->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@error('recipient_branch_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>@endif
                <div class="space-y-1">@foreach($this->categories() as $key => $label)<label class="flex gap-2 text-xs"><input type="checkbox" value="{{ $key }}" wire:model="recipient_categories" class="rounded text-build-orange"> {{ $label }}</label>@endforeach</div>
                <button class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white dark:bg-white dark:text-slate-900">Add Recipient</button>
            </form>
            <div class="mt-5 space-y-2">@foreach(WhatsAppRecipient::withoutGlobalScopes()->where('company_id',$companyId)->latest()->get() as $recipient)<div wire:key="recipient-{{ $recipient->id }}" class="rounded-xl border p-3 text-sm dark:border-slate-700"><div class="flex justify-between gap-2"><div><div class="font-black">{{ $recipient->name }}</div><div class="text-xs text-slate-500">{{ $recipient->phone }} · {{ ucfirst($recipient->scope) }}</div></div><div class="flex gap-1"><button wire:click="toggleRecipient({{ $recipient->id }})" class="text-xs font-bold {{ $recipient->active ? 'text-emerald-600' : 'text-slate-400' }}">{{ $recipient->active ? 'Active' : 'Paused' }}</button><button wire:click="deleteRecipient({{ $recipient->id }})" wire:confirm="Remove this recipient?" class="text-xs font-bold text-red-600">Remove</button></div></div></div>@endforeach</div>
        </x-card>

        <x-card class="xl:col-span-3">
            <h2 class="text-lg font-black">Message Templates</h2>
            <p class="mt-1 text-sm text-slate-500">Only the listed placeholders are replaced. HARDEX supplies authorized values; templates cannot query other data.</p>
            <form wire:submit="saveTemplates" class="mt-4 grid gap-4 lg:grid-cols-3">
                @foreach(WhatsAppTemplate::withoutGlobalScopes()->where('company_id',$companyId)->orderBy('name')->get() as $template)
                    <label class="block text-sm font-bold">{{ $template->name }}<textarea wire:model="template_bodies.{{ $template->key }}" class="mt-1 min-h-52 w-full rounded-lg border-slate-200 font-mono text-xs dark:bg-navy-950"></textarea></label>
                @endforeach
                <div class="lg:col-span-3"><button class="rounded-xl border px-4 py-2.5 text-sm font-black">Save Templates</button></div>
            </form>
        </x-card>
    </div>
</div>
