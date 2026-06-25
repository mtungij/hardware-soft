<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\rules;
use function Livewire\Volt\state;

layout('layouts.app');

state([
    'mail_host' => '',
    'mail_port' => '',
    'mail_username' => '',
    'mail_password' => '',
    'mail_encryption' => '',
    'mail_from_email' => '',
    'mail_from_name' => '',
    'test_email' => '',
    'statusType' => '',
    'statusMessage' => '',
]);

rules([
    'mail_host' => ['required', 'string', 'max:255'],
    'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
    'mail_username' => ['nullable', 'string', 'max:255'],
    'mail_password' => ['nullable', 'string', 'max:255'],
    'mail_encryption' => ['nullable', 'in:tls,ssl'],
    'mail_from_email' => ['required', 'email:rfc', 'max:255'],
    'mail_from_name' => ['required', 'string', 'max:255'],
    'test_email' => ['nullable', 'email:rfc', 'max:255'],
]);

mount(function () {
    $settings = Setting::first();
    $this->mail_host = $settings?->mail_host ?: config('mail.mailers.smtp.host');
    $this->mail_port = (string) ($settings?->mail_port ?: config('mail.mailers.smtp.port'));
    $this->mail_username = $settings?->mail_username;
    $this->mail_encryption = $settings?->mail_encryption;
    $this->mail_from_email = $settings?->mail_from_email ?: config('mail.from.address');
    $this->mail_from_name = $settings?->mail_from_name ?: config('mail.from.name');
});

$applyConfig = function () {
    Config::set('mail.mailers.buildmart_smtp', [
        'transport' => 'smtp',
        'scheme' => $this->mail_encryption === 'ssl' ? 'smtps' : 'smtp',
        'url' => null,
        'host' => trim((string) $this->mail_host),
        'port' => (int) $this->mail_port,
        'username' => filled($this->mail_username) ? trim((string) $this->mail_username) : null,
        'password' => filled($this->mail_password) ? trim((string) $this->mail_password) : Setting::first()?->mail_password,
        'timeout' => 30,
        'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
    ]);
    Config::set('mail.from.address', trim((string) $this->mail_from_email));
    Config::set('mail.from.name', trim((string) $this->mail_from_name));
    Mail::forgetMailers();
};

$save = function () {
    abort_unless(auth()->user()->can('manage email settings'), 403);
    $data = $this->validate();
    $settings = Setting::firstOrFail();

    $settings->fill([
        'mail_host' => trim((string) $data['mail_host']),
        'mail_port' => $data['mail_port'],
        'mail_username' => filled($data['mail_username']) ? trim((string) $data['mail_username']) : null,
        'mail_encryption' => $data['mail_encryption'],
        'mail_from_email' => trim((string) $data['mail_from_email']),
        'mail_from_name' => trim((string) $data['mail_from_name']),
    ]);

    if (filled($data['mail_password'])) {
        $settings->mail_password = trim((string) $data['mail_password']);
    }

    $settings->save();
    $this->mail_password = '';
    $this->statusType = 'success';
    $this->statusMessage = 'Email settings saved successfully.';

    session()->flash('success', 'Email settings saved successfully.');
};

$sendTestEmail = function () {
    abort_unless(auth()->user()->can('manage email settings'), 403);
    $this->validate();
    $this->applyConfig();

    try {
        Mail::mailer('buildmart_smtp')->raw('Hardex POS SMTP test email sent successfully.', function ($message) {
            $message->to($this->test_email ?: $this->mail_from_email)
                ->subject('Hardex SMTP Test');
        });
        $this->statusType = 'success';
        $this->statusMessage = 'Test email sent successfully to '.($this->test_email ?: $this->mail_from_email).'.';
        session()->flash('success', 'Test email sent successfully.');
    } catch (\Throwable $exception) {
        $this->statusType = 'error';
        $this->statusMessage = 'Unable to send email: '.$exception->getMessage();
        session()->flash('error', $this->statusMessage);
    }
};

?>

<div>
    <x-page-header title="Email Settings" description="Configure SMTP settings for supplier purchase order emails." :breadcrumbs="['Dashboard' => route('dashboard'), 'Email Settings' => null]" />

    <x-card class="max-w-4xl">
        @if ($statusMessage)
            <div class="mb-5 rounded-xl border px-4 py-3 text-sm font-semibold {{ $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200' }}">
                {{ $statusMessage }}
            </div>
        @endif

        <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
            <x-form-input label="Mail Host" name="mail_host" wire:model="mail_host" required />
            <x-form-input label="Mail Port" name="mail_port" wire:model="mail_port" type="number" required />
            <x-form-input label="Username" name="mail_username" wire:model="mail_username" />
            <x-form-input label="Password" name="mail_password" wire:model="mail_password" type="password" placeholder="Leave blank to keep current password" />
            <x-form-select label="Encryption" name="mail_encryption" wire:model="mail_encryption">
                <option value="">None</option>
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
            </x-form-select>
            <x-form-input label="From Email" name="mail_from_email" wire:model="mail_from_email" type="email" required />
            <x-form-input label="From Name" name="mail_from_name" wire:model="mail_from_name" required />
            <x-form-input label="Test Recipient Email" name="test_email" wire:model="test_email" type="email" placeholder="Defaults to From Email" />
            <div class="flex flex-wrap gap-3 md:col-span-2">
                <button class="rounded-lg bg-build-orange px-4 py-2 text-sm font-bold text-white" wire:loading.attr="disabled">Save Settings</button>
                <button type="button" wire:click="sendTestEmail" wire:loading.attr="disabled" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-bold dark:border-slate-700">
                    <span wire:loading.remove wire:target="sendTestEmail">Send Test Email</span>
                    <span wire:loading wire:target="sendTestEmail">Sending...</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
