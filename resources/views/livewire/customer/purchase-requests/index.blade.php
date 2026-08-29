<?php
use App\Models\CustomerPurchaseRequest;
use function Livewire\Volt\computed; use function Livewire\Volt\layout;
layout('layouts.customer');
$requests = computed(fn()=>CustomerPurchaseRequest::withoutGlobalScopes()->where('company_id',auth('customer')->user()->company_id)->where('customer_account_id',auth('customer')->id())->with('branch')->latest()->paginate(15));
?>
<div><x-page-header title="My Purchase Requests" description="Track requests from submission through quotation and sale." :breadcrumbs="['Customer Portal'=>route('customer.dashboard'),'Purchase Requests'=>null]"><a href="{{ route('customer.purchase-requests.create') }}" wire:navigate class="rounded-xl bg-build-orange px-4 py-2 text-sm font-black text-white">New Request</a></x-page-header>
<x-card><x-table :headers="['Request','Branch','Submitted','Items','Status','']">@forelse($this->requests as $request)<tr><td class="px-4 py-3 font-black">{{ $request->request_number }}</td><td class="px-4 py-3">{{ $request->branch?->name }}</td><td class="px-4 py-3">{{ $request->submitted_at->format('d M Y H:i') }}</td><td class="px-4 py-3">{{ $request->items()->count() }}</td><td class="px-4 py-3">{{ str($request->status)->headline() }}</td><td class="px-4 py-3"><a class="font-bold text-build-orange" href="{{ route('customer.purchase-requests.show',$request) }}" wire:navigate>View</a></td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No purchase requests yet.</td></tr>@endforelse</x-table><div class="mt-4">{{ $this->requests->links() }}</div></x-card></div>
