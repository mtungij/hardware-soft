<?php

namespace App\Http\Middleware;

use App\Support\ExpenseAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureExpenseAccess
{
    public function handle(Request $request, Closure $next)
    {
        ExpenseAccess::authorizeEntry($request->user());

        return $next($request);
    }
}
