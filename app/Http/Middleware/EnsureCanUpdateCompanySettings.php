<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanUpdateCompanySettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $authorized = $user && (
            $user->hasAnyRole(['Super Admin', 'Admin'])
            || $user->can('company-settings.update')
        );

        abort_unless($authorized, 403);

        return $next($request);
    }
}
