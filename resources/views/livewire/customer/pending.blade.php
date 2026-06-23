<?php

use function Livewire\Volt\layout;

layout('layouts.customer');

?>

<div class="mx-auto max-w-2xl">
    <div class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-500/30 dark:bg-amber-500/10">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-amber-500 text-2xl font-black text-white">!</div>
        <h1 class="mt-6 text-2xl font-black text-navy-900 dark:text-white">Account Pending Approval</h1>
        <p class="mt-3 text-sm leading-6 text-amber-800 dark:text-amber-100">Your Hardex customer portal account has been created successfully. An administrator must approve it before you can access debts, receipts, deposits, and statements.</p>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="https://wa.me/255629364847" target="_blank" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white">Contact WhatsApp Support</a>
            <form method="POST" action="{{ route('customer.logout') }}">
                @csrf
                <button class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-black dark:border-slate-700">Logout</button>
            </form>
        </div>
    </div>
</div>
