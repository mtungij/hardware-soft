<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Support\AuthorizationScope;
use Illuminate\Http\RedirectResponse;

class CompletePosReceiptController extends Controller
{
    public function __invoke(Sale $sale): RedirectResponse
    {
        AuthorizationScope::authorizeSale(request()->user(), $sale);

        session()->flash(
            'success',
            "Mauzo yamekamilika kwa mafanikio. Risiti {$sale->sale_number} imehifadhiwa."
        );

        return redirect()->route('pos.index');
    }
}
