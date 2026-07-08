<?php

namespace App\Http\Middleware;

use App\Support\InventorySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWarehouseEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(InventorySettings::warehouseEnabled(), 403);

        return $next($request);
    }
}
