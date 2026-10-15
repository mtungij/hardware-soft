<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerPurchaseRequest;
use App\Models\Quotation;
use App\Services\B2bQuotationService;
use App\Services\CustomerPortalCatalogue;
use App\Services\CustomerPurchaseRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerOrderingController extends Controller
{
    public function products(Request $request, CustomerPortalCatalogue $catalogue)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'between:1,50']]);
        $account = $request->user();
        $branch = $catalogue->branch($account, $data['branch_id'] ?? null);
        $rows = $catalogue->products($account, $branch->id, $data['search'] ?? '')->paginate($data['per_page'] ?? 20);

        return response()->json($rows->through(fn ($product) => $catalogue->payload($product, $account, $branch)))->header('Cache-Control', 'private, no-store');
    }

    public function requests(Request $request)
    {
        return response()->json(CustomerPurchaseRequest::withoutGlobalScopes()
            ->where('company_id', $request->user()->company_id)->where('customer_id', $request->user()->customer_id)
            ->latest()->paginate(20)->through(fn ($row) => $this->requestPayload($row)));
    }

    public function store(Request $request, CustomerPurchaseRequestService $service)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'], 'submission_key' => ['required', 'uuid'],
            'request_type' => ['required', Rule::in(['rfq', 'order'])], 'notes' => ['nullable', 'string', 'max:2000'],
            'delivery_requested' => ['sometimes', 'boolean'], 'delivery_address' => ['nullable', 'required_if:delivery_requested,true', 'string', 'max:2000'],
            'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.product_unit_conversion_id' => ['nullable', 'integer'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $row = $service->submit($request->user(), $data['branch_id'] ?? null, $data['notes'] ?? null, $data['items'], $data['submission_key'], $data);

        return response()->json($this->requestPayload($row), 201);
    }

    public function quotations(Request $request)
    {
        return response()->json(Quotation::withoutGlobalScopes()->where('company_id', $request->user()->company_id)
            ->where('customer_id', $request->user()->customer_id)->whereNotIn('status', ['draft', 'cancelled'])
            ->latest()->paginate(20)->through(fn ($row) => [
                'id' => $row->id, 'number' => $row->quotation_number, 'status' => $row->status,
                'total_amount' => (float) $row->total_amount, 'valid_until' => $row->valid_until?->toDateString(),
            ]));
    }

    public function decide(Request $request, int $quotation, B2bQuotationService $service)
    {
        $data = $request->validate(['decision' => ['required', 'in:accept,decline'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $row = Quotation::withoutGlobalScopes()->where('company_id', $request->user()->company_id)
            ->where('customer_id', $request->user()->customer_id)->findOrFail($quotation);
        $row = $data['decision'] === 'accept' ? $service->accept($row, $request->user()) : $service->reject($row, $request->user(), $data['reason'] ?? null);

        return response()->json(['id' => $row->id, 'status' => $row->status]);
    }

    private function requestPayload(CustomerPurchaseRequest $row): array
    {
        return [
            'id' => $row->id, 'number' => $row->request_number, 'status' => $row->status,
            'request_type' => $row->request_type, 'delivery_requested' => (bool) $row->delivery_requested,
            'delivery_address' => $row->delivery_address, 'delivery_instructions' => $row->delivery_instructions,
            'notes' => $row->customer_notes,
            'items' => $row->items->map(fn ($item) => [
                'product_id' => $item->product_id, 'name' => $item->product_name_snapshot,
                'unit' => $item->transaction_unit_name_snapshot, 'quantity' => (float) $item->transaction_quantity,
                'notes' => $item->customer_notes,
            ])->values()->all(),
        ];
    }
}
