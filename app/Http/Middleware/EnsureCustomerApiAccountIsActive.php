<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerApiAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($account->status === 'pending') {
            return response()->json([
                'message' => 'Customer account is pending approval.',
                'status' => 'pending',
            ], 423);
        }

        if ($account->status === 'suspended') {
            $account->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Customer account is suspended.',
                'status' => 'suspended',
            ], 403);
        }

        return $next($request);
    }
}
