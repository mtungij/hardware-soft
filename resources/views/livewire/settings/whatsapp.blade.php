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
use App\Services\WhatsAppLocalization;
use App\Services\WhatsAppTemplateService;
use App\Support\WhatsAppPhone;
use App\Support\WhatsAppCategories;
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
    'whatsapp_notification_language' => 'en',
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
    $this->recipient_categories = WhatsAppCategories::filter($company->id, $this->recipient_categories);
    app(WhatsAppTemplateService::class)->seedDefaults($company);
    $this->template_bodies = WhatsAppTemplate::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $company->id)->whereIn('category', array_keys(WhatsAppCategories::available($company->id)))->get()->mapWithKeys(fn ($template) => [$template->key => app(WhatsAppTemplateService::class)->bodyForLanguage($template, app()->getLocale())])->all();
    $setting = CompanyWhatsAppSetting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->firstOrCreate(
        ['company_id' => $company->id],
        ['timezone' => $company->timezone ?: 'Africa/Dar_es_Salaam', 'whatsapp_notification_language' => 'en', 'enabled_categories' => CompanyWhatsAppSetting::DEFAULT_CATEGORIES]
    );

    foreach (['enabled', 'sending_paused', 'device_id', 'timezone', 'whatsapp_notification_language', 'daily_summary_time', 'attach_daily_summary_pdf', 'debt_reminders_enabled', 'debt_due_tomorrow_enabled', 'debt_due_today_enabled', 'debt_overdue_enabled', 'debt_reminder_time', 'debt_overdue_interval_days', 'attach_debt_summary_pdf', 'quiet_hours_start', 'quiet_hours_end', 'enabled_categories', 'minimum_send_interval_seconds', 'maximum_messages_per_minute', 'maximum_messages_per_hour', 'low_stock_cooldown_hours', 'attach_stock_alert_pdf', 'test_recipient', 'last_device_state', 'last_checked_at'] as $field) {
        $value = $setting->{$field};
        if (in_array($field, ['daily_summary_time', 'debt_reminder_time', 'quiet_hours_start', 'quiet_hours_end'], true) && is_string($value)) {
            $value = substr($value, 0, 5);
        }
        $this->{$field} = $value instanceof DateTimeInterface ? $value->locale(app()->getLocale())->translatedFormat('d M Y H:i') : ($value ?? $this->{$field});
    }
    $this->enabled_categories = WhatsAppCategories::filter($company->id, $this->enabled_categories);
});

$categories = fn (): array => WhatsAppCategories::available($this->companyId);

$save = function (Gowa $gowa, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $data = $this->validate([
        'enabled' => ['boolean'], 'sending_paused' => ['boolean'],
        'device_id' => ['nullable', 'string', 'max:255'],
        'timezone' => ['required', 'timezone'], 'daily_summary_time' => ['required', 'date_format:H:i'],
        'whatsapp_notification_language' => ['required', Rule::in(['en', 'sw'])],
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
        $this->addError('test_recipient', \App\Support\UiText::feedback($exception->getMessage()));

        return;
    }

    if ($data['enabled']) {
        if (blank($data['device_id'])) {
            $this->addError('device_id', __('application.a_device_id_is_required_before_enabling_whatsapp_notifications_34c6da73'));

            return;
        }

        try {
            $state = $gowa->deviceState($gowa->deviceStatus($data['device_id']));
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('device_id', __('application.could_not_verify_the_whatsapp_device_check_the_device_id_and_gowa_service_1afbd63b'));

            return;
        }
        if ($state !== 'logged_in') {
            $this->addError('device_id', __('application.whatsapp_device_is_not_connected_re_link_the_device_before_enabling_notific_f18db1cd'));

            return;
        }
        $data['last_device_state'] = $state;
        $data['last_checked_at'] = now();
    }

    $setting = CompanyWhatsAppSetting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->first();
    $before = $setting?->only(array_keys($data));
    $setting = CompanyWhatsAppSetting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->updateOrCreate(['company_id' => $this->companyId], $data);
    $audit->record($this->companyId, 'settings_updated', $before, $setting->only(array_keys($data)));
    $this->last_device_state = $data['last_device_state'] ?? $this->last_device_state;
    $this->last_checked_at = isset($data['last_checked_at']) ? now()->locale(app()->getLocale())->translatedFormat('d M Y H:i') : $this->last_checked_at;
    session()->flash('success', __('application.whatsapp_notification_settings_saved_fbef2445'));
};

$testConnection = function (Gowa $gowa): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $this->validate(['device_id' => ['required', 'string', 'max:255']]);
    try {
        $state = $gowa->deviceState($gowa->deviceStatus(trim($this->device_id)));
        CompanyWhatsAppSetting::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->update(['last_device_state' => $state, 'last_checked_at' => now()]);
        $this->last_device_state = $state;
        $this->last_checked_at = now()->locale(app()->getLocale())->translatedFormat('d M Y H:i');
        session()->flash($state === 'logged_in' ? 'success' : 'error', $state === 'logged_in' ? __('terminology.whatsapp_device_is_connected_and_logged_in_82723233') : __('terminology.whatsapp_device_is_not_connected_re_link_it_before_enabling_notifications_bfcf918d'));
    } catch (Throwable $exception) {
        report($exception);
        $this->last_device_state = 'unknown';
        session()->flash('error', __('application.could_not_verify_the_whatsapp_device_check_the_device_id_and_gowa_service_1afbd63b'));
    }
};

$testMessage = function (WhatsAppNotificationService $notifications): void {
    abort_unless(auth()->user()->can('whatsapp.manage_settings'), 403);
    $this->validate(['test_recipient' => ['required', 'string', 'max:30']]);
    $company = Company::query()->findOrFail($this->companyId);
    $localization = app(WhatsAppLocalization::class);
    $notification = $notifications->queueTest($company, $this->test_recipient, $localization->get($company, 'test.title')."\n{$company->company_name}\n".$localization->date($company, now(), true));
    session()->flash($notification->status === 'queued' ? 'success' : 'error', $notification->status === 'queued' ? __('terminology.test_message_queued_check_the_notification_log_for_delivery_status_50e3ba9c') : \App\Support\UiText::feedback($notification->failure_reason));
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
        $this->addError('recipient_branch_id', __('application.select_a_branch_for_a_branch_scoped_recipient_fa4ef8cd'));

        return;
    }

    try {
        $phone = WhatsAppPhone::normalize($data['recipient_phone']);
    } catch (Throwable $exception) {
        $this->addError('recipient_phone', \App\Support\UiText::feedback($exception->getMessage()));

        return;
    }

    $recipient = WhatsAppRecipient::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->create([
        'company_id' => $this->companyId, 'name' => $data['recipient_name'],
        'phone' => $phone,
        'user_id' => $data['recipient_user_id'] ?: null,
        'scope' => $data['recipient_scope'],
        'branch_id' => $data['recipient_scope'] === 'branch' ? $data['recipient_branch_id'] : null,
        'categories' => $data['recipient_categories'], 'active' => true,
    ]);
    $audit->record($this->companyId, 'recipient_added', null, $recipient->only(['id', 'name', 'phone', 'user_id', 'branch_id', 'scope', 'categories', 'active']));
    $this->reset('recipient_name', 'recipient_phone', 'recipient_user_id', 'recipient_branch_id');
    session()->flash('success', __('application.whatsapp_recipient_added_a2a38340'));
};

$toggleRecipient = function (int $id, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_recipients'), 403);
    $recipient = WhatsAppRecipient::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->findOrFail($id);
    $before = $recipient->only(['id', 'active']);
    $recipient->update(['active' => ! $recipient->active]);
    $audit->record($this->companyId, 'recipient_status_changed', $before, $recipient->only(['id', 'active']));
};

$deleteRecipient = function (int $id, WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_recipients'), 403);
    $recipient = WhatsAppRecipient::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->findOrFail($id);
    $before = $recipient->only(['id', 'name', 'phone', 'user_id', 'branch_id', 'scope', 'categories', 'active']);
    $recipient->delete();
    $audit->record($this->companyId, 'recipient_removed', $before);
};

$saveTemplates = function (WhatsAppAuditService $audit): void {
    abort_unless(auth()->user()->can('whatsapp.manage_templates'), 403);
    $this->validate(['template_bodies' => ['required', 'array'], 'template_bodies.*' => ['required', 'string', 'max:4000']]);
    foreach ($this->template_bodies as $key => $body) {
        $template = WhatsAppTemplate::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id', $this->companyId)->whereIn('category', array_keys($this->categories()))->where('key', $key)->first();
        if ($template) {
            app(WhatsAppTemplateService::class)->saveBodyForLanguage($template, app()->getLocale(), $body);
        }
    }
    $audit->record($this->companyId, 'templates_updated', null, ['template_keys' => array_keys($this->template_bodies)]);
    session()->flash('success', __('application.whatsapp_templates_saved_5f15a92f'));
};

?>

<div>
    <x-page-header title="{{ __('application.whatsapp_notifications_9fabc587') }}" description="{{ __('application.configure_this_company_s_linked_device_responsible_delivery_controls_recipi_d778c43f') }}" :breadcrumbs="['Dashboard' => route('dashboard'), 'Settings' => route('settings.index'), 'WhatsApp' => null]" />

    @if (session('success')) <div class="mb-4 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700">{{ session('success') }}</div> @endif
    @if (session('error')) <div class="mb-4 rounded-xl bg-red-50 p-4 text-sm font-bold text-red-700">{{ session('error') }}</div> @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <x-card class="xl:col-span-2">
            <form wire:submit="save" class="space-y-5">
                <div class="flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="enabled" class="rounded text-build-orange"> {{ __('application.enable_notifications_da3967d0') }}</label>
                    <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="sending_paused" class="rounded text-build-orange"> {{ __('application.pause_sending_b3faedb2') }}</label>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-form-input label="{{ __('application.company_device_id_d8aaa319') }}" name="device_id" wire:model="device_id" />
                    <div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <div class="font-black">{{ __('application.device_153f113c') }} {{ \App\Support\UiText::label($last_device_state ?: 'unknown') }}</div>
                        <div class="text-xs text-slate-500">{{ __('application.last_checked_80fca14b') }} {{ $last_checked_at ?: __('application.never_80c3052d') }}</div>
                        <button type="button" wire:click="testConnection" wire:loading.attr="disabled" class="mt-2 rounded-lg border px-3 py-1.5 text-xs font-black">{{ __('application.test_connection_580f6ab7') }}</button>
                    </div>
                    <x-form-input label="{{ __('application.timezone_d1f7dc89') }}" name="timezone" wire:model="timezone" />
                    <label class="block text-sm font-bold">
                        {{ __('application.notification_language_258d5e91') }}
                        <select wire:model="whatsapp_notification_language" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950">
                            <option value="en">{{ __('application.english_649df08a') }}</option>
                            <option value="sw">{{ __('application.kiswahili_1fa52793') }}</option>
                        </select>
                        <span class="mt-1 block text-xs font-normal text-slate-500">{{ __('application.controls_the_language_used_for_automatic_whatsapp_notifications_and_report__1e918a52') }}</span>
                        @error('whatsapp_notification_language')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <x-form-input label="{{ __('application.daily_summary_time_33d0fbe8') }}" name="daily_summary_time" type="time" wire:model="daily_summary_time" />
                    <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_daily_summary_pdf" class="rounded text-build-orange"> {{ __('application.attach_permission_filtered_daily_pdf_f07fb288') }}</label>
                    <label class="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_reminders_enabled" class="rounded text-build-orange"> {{ __('application.enable_transactional_debt_reminders_bd43ddb8') }}</label>
                    <x-form-input label="{{ __('application.debt_reminder_time_3149b429') }}" name="debt_reminder_time" type="time" wire:model="debt_reminder_time" />
                    <x-form-input label="{{ __('application.overdue_reminder_interval_days_64789d28') }}" name="debt_overdue_interval_days" type="number" wire:model="debt_overdue_interval_days" />
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_due_tomorrow_enabled" class="rounded text-build-orange"> {{ __('application.due_tomorrow_4fc752fe') }}</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_due_today_enabled" class="rounded text-build-orange"> {{ __('application.due_today_11ea0f15') }}</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="debt_overdue_enabled" class="rounded text-build-orange"> {{ __('application.overdue_07217c77') }}</label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_debt_summary_pdf" class="rounded text-build-orange"> {{ __('application.attach_scoped_debtor_pdf_to_management_summary_7aa9512e') }}</label>
                    <x-form-input label="{{ __('application.quiet_hours_start_be8cc8a8') }}" name="quiet_hours_start" type="time" wire:model="quiet_hours_start" />
                    <x-form-input label="{{ __('application.quiet_hours_end_34bd3176') }}" name="quiet_hours_end" type="time" wire:model="quiet_hours_end" />
                    <x-form-input label="{{ __('application.minimum_seconds_between_messages_7727216b') }}" name="minimum_send_interval_seconds" type="number" wire:model="minimum_send_interval_seconds" />
                    <x-form-input label="{{ __('application.maximum_messages_minute_8f11ece4') }}" name="maximum_messages_per_minute" type="number" wire:model="maximum_messages_per_minute" />
                    <x-form-input label="{{ __('application.maximum_messages_hour_2581ce14') }}" name="maximum_messages_per_hour" type="number" wire:model="maximum_messages_per_hour" />
                    <x-form-input label="{{ __('application.low_stock_cooldown_hours_1d0c1f20') }}" name="low_stock_cooldown_hours" type="number" wire:model="low_stock_cooldown_hours" />
                    <label class="flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 text-sm font-bold dark:border-slate-700"><input type="checkbox" wire:model="attach_stock_alert_pdf" class="rounded text-build-orange"> {{ __('application.attach_low_out_of_stock_pdf_3fedbd2b') }}</label>
                </div>
                <div><div class="mb-2 text-sm font-black">{{ __('application.enabled_categories_3acec7b6') }}</div><div class="grid gap-2 md:grid-cols-2">@foreach($this->categories() as $key => $label)<label class="flex gap-2 text-sm"><input type="checkbox" value="{{ $key }}" wire:model="enabled_categories" class="rounded text-build-orange"> {{ $label }}</label>@endforeach</div></div>
                <div class="flex gap-3"><button class="rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white">{{ __('application.save_settings_0022a3e1') }}</button><a href="{{ route('settings.whatsapp.logs') }}" class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.notification_log_9353fa55') }}</a></div>
            </form>
            <div class="mt-6 border-t pt-5 dark:border-slate-700"><div class="flex gap-3"><div class="flex-1"><x-form-input label="{{ __('application.test_recipient_4bdfd4ca') }}" name="test_recipient" wire:model="test_recipient" placeholder="0764xxxxxx" /></div><button type="button" wire:click="testMessage" class="self-end rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.queue_test_message_c3a112d9') }}</button></div></div>
        </x-card>

        <x-card>
            <h2 class="text-lg font-black">{{ __('application.recipients_78cbf8eb') }}</h2>
            <form wire:submit="addRecipient" class="mt-4 space-y-3">
                <x-form-input label="{{ __('application.name_role_0fe20a09') }}" name="recipient_name" wire:model="recipient_name" />
                <x-form-input label="{{ __('application.phone_77064d52') }}" name="recipient_phone" wire:model="recipient_phone" />
                <label class="block text-sm font-bold">{{ __('application.staff_user_6befd655') }}<select wire:model="recipient_user_id" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="">{{ __('application.phone_only_recipient_7b6fc9a2') }}</option>@foreach(User::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$companyId)->where('status','active')->orderBy('name')->get() as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
                <label class="block text-sm font-bold">{{ __('application.scope_4651a34e') }}<select wire:model.live="recipient_scope" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950"><option value="company">{{ __('application.company_7a199499') }}</option><option value="branch">{{ __('application.branch_1627510b') }}</option></select></label>
                @if($recipient_scope === 'branch')<label class="block text-sm font-bold">{{ __('application.branch_1627510b') }}<select wire:model="recipient_branch_id" class="mt-1 w-full rounded-lg border-slate-200 dark:bg-navy-950" @disabled(\App\Support\BranchAccess::restricted())><option value="">{{ __('application.select_branch_fcf716ea') }}</option>@foreach(Branch::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$companyId)->orderBy('name')->get() as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@error('recipient_branch_id')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</label>@endif
                <div class="space-y-1">@foreach($this->categories() as $key => $label)<label class="flex gap-2 text-xs"><input type="checkbox" value="{{ $key }}" wire:model="recipient_categories" class="rounded text-build-orange"> {{ $label }}</label>@endforeach</div>
                <button class="w-full rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white dark:bg-white dark:text-slate-900">{{ __('application.add_recipient_81763462') }}</button>
            </form>
            <div class="mt-5 space-y-2">@foreach(WhatsAppRecipient::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$companyId)->latest()->get() as $recipient)<div wire:key="recipient-{{ $recipient->id }}" class="rounded-xl border p-3 text-sm dark:border-slate-700"><div class="flex justify-between gap-2"><div><div class="font-black">{{ $recipient->name }}</div><div class="text-xs text-slate-500">{{ $recipient->phone }} · {{ \App\Support\UiText::label($recipient->scope) }}</div></div><div class="flex gap-1"><button wire:click="toggleRecipient({{ $recipient->id }})" class="text-xs font-bold {{ $recipient->active ? 'text-emerald-600' : 'text-slate-400' }}">{{ $recipient->active ? __('application.active_a733b809') : __('terminology.paused_c7dfb6f1') }}</button><button wire:click="deleteRecipient({{ $recipient->id }})" wire:confirm="{{ __('application.remove_this_recipient_49a02127') }}" class="text-xs font-bold text-red-600">{{ __('application.remove_e963907d') }}</button></div></div></div>@endforeach</div>
        </x-card>

        <x-card class="xl:col-span-3">
            <h2 class="text-lg font-black">{{ __('application.message_templates_282a801f') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('application.only_the_listed_placeholders_are_replaced_hardex_supplies_authorized_values_fcef7fd9') }}</p>
            <form wire:submit="saveTemplates" class="mt-4 grid gap-4 lg:grid-cols-3">
                @foreach(WhatsAppTemplate::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->where('company_id',$companyId)->whereIn('category', array_keys($this->categories()))->orderBy('name')->get() as $template)
                    <label class="block text-sm font-bold">{{ \App\Support\UiText::translate($template->name) }}<textarea wire:model="template_bodies.{{ $template->key }}" class="mt-1 min-h-52 w-full rounded-lg border-slate-200 font-mono text-xs dark:bg-navy-950"></textarea></label>
                @endforeach
                <div class="lg:col-span-3"><button class="rounded-xl border px-4 py-2.5 text-sm font-black">{{ __('application.save_templates_1ec76ba1') }}</button></div>
            </form>
        </x-card>
    </div>
</div>
