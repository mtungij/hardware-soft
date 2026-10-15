<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer) {
            return redirect()->route('customer.login');
        }


        if ($customer->status === 'pending') {
            return redirect()->route('customer.pending');
        }

        if ($customer->status === 'suspended') {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('customer.login')->with('error', __('application.your_customer_portal_account_is_suspended_please_contact_hardex_support_c2d25c80'));
        }

        return $next($request);
    }
}
