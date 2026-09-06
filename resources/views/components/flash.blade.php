@php
    $initialTone = session('error') ? 'error' : 'success';
    $initialMessage = session('error') ?: session('success');
    $initialTitle = session('error_title') ?: session('success_title');
@endphp

<div
    x-cloak
    class="fixed right-4 top-20 z-50 w-[calc(100%-2rem)] max-w-md sm:right-6"
    x-data="{
        show: @js((bool) $initialMessage),
        title: @js($initialTitle),
        message: @js($initialMessage),
        tone: @js($initialTone),
        timer: null,
        scheduleHide() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.show = false, 5000);
        },
        notify(detail) {
            this.title = detail.title || '';
            this.message = detail.message || '';
            this.tone = detail.tone === 'error' ? 'error' : 'success';
            this.show = true;
            this.scheduleHide();
        },
    }"
    x-init="if (show) scheduleHide()"
    x-on:hardex-notify.window="notify($event.detail)"
    x-show="show && message"
    x-transition
>
    <div
        :role="tone === 'error' ? 'alert' : 'status'"
        class="rounded-xl border px-4 py-3 text-sm font-semibold shadow-soft"
        :class="tone === 'error'
            ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200'
            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'"
    >
        <p x-show="title" class="font-black" x-text="title"></p>
        <p :class="title ? 'mt-1' : ''" x-text="message"></p>
    </div>
</div>
