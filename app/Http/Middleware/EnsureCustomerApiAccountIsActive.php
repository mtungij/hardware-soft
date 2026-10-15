<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerApiAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof CustomerAccount || ! $account->customer || (int) $account->customer->company_id !== (int) $account->company_id) {
            return response()->json(['message' => __('application.unauthenticated_5d595b3c')], 401);
        }

        if ($account->status === 'pending') {
            return response()->json([
                'message' => __('application.customer_account_is_pending_approval_d3bd7bc4'),
                'status' => 'pending',
            ], 423);
        }

        if ($account->status === 'suspended') {
            $account->currentAccessToken()?->delete();

            return response()->json([
                'message' => __('application.customer_account_is_suspended_49be818a'),
                'status' => 'suspended',
            ], 403);
        }


        return $next($request);
    }
}
