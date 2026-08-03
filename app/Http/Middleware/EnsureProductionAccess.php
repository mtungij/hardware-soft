<?php

namespace App\Http\Middleware;

use App\Support\CompanyFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionAccess
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $permissions = $permissions ?: ['production.view'];

        abort_unless(
            $user
            && CompanyFeatures::manufacturingEnabled()
            && collect($permissions)->contains(fn (string $permission) => $user->can($permission)),
            403
        );

        return $next($request);
    }
}
