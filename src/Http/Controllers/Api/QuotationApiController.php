<?php

namespace Zerp\Quotation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Zerp\Quotation\Events\AcceptSalesQuotation;
use Zerp\Quotation\Events\CreateQuotation;
use Zerp\Quotation\Events\DestroyQuotation;
use Zerp\Quotation\Events\RejectSalesQuotation;
use Zerp\Quotation\Events\SentSalesQuotation;
use Zerp\Quotation\Events\UpdateQuotation;
use Zerp\Quotation\Http\Requests\Api\StoreQuotationApiRequest;
use Zerp\Quotation\Http\Requests\Api\UpdateQuotationApiRequest;
use Zerp\Quotation\Models\SalesQuotation;
use Zerp\Quotation\Models\SalesQuotationItem;
use Zerp\Quotation\Models\SalesQuotationItemTax;

/**
 * REST API for the Quotation module, backing the Flutter app. Mirrors the web
 * QuotationController: the same manage-any / manage-own visibility, company
 * (created_by) tenant scoping, the draft -> sent -> accepted/rejected status
 * flow, and server-side total calculation. Responses use the shared
 * {success, message, data} envelope. See zerp-pk/zerp#25.
 *
 * Endpoints take an id and query with the visibility scope rather than relying
 * on route-model binding: these routes run the bare api.json middleware, not
 * Laravel's api group, so SubstituteBindings never runs and a type-hinted model
 * would arrive empty. Querying by id also lets an out-of-scope id return 404.
 */
class QuotationApiController extends Controller
{
    use ApiResponseTrait;

    private const SORTABLE = ['quotation_number', 'quotation_date', 'due_date', 'subtotal', 'tax_amount', 'total_amount', 'status', 'created_at'];

    public function index(Request $request)
    {
        try {
            if (!Auth::user()->can('manage-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotations = SalesQuotation::with(['customer:id,name,email', 'items'])
                ->visibleTo()
                ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->search, fn ($q, $s) => $q->where('quotation_number', 'like', "%{$s}%"))
                ->orderBy($this->sortField($request), $this->sortDirection($request))
                ->paginate($request->get('per_page', 10))
                ->withQueryString();

            $quotations->getCollection()->transform(fn ($q) => $this->present($q));

            return $this->paginatedResponse($quotations, __('Quotations retrieved successfully'));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function store(StoreQuotationApiRequest $request)
    {
        try {
            if (!Auth::user()->can('create-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotation = DB::transaction(function () use ($request) {
                $totals = $this->calculateTotals($request->items);

                $quotation = new SalesQuotation();
                $quotation->fill([
                    'quotation_date'  => $request->invoice_date,
                    'due_date'        => $request->due_date,
                    'customer_id'     => $request->customer_id,
                    'warehouse_id'    => $request->warehouse_id,
                    'payment_terms'   => $request->payment_terms,
                    'notes'           => $request->notes,
                    'subtotal'        => $totals['subtotal'],
                    'tax_amount'      => $totals['tax_amount'],
                    'discount_amount' => $totals['discount_amount'],
                    'total_amount'    => $totals['total_amount'],
                ]);
                $quotation->creator_id = Auth::id();
                $quotation->created_by = creatorId();
                $quotation->save();

                $this->syncItems($quotation->id, $request->items);

                return $quotation;
            });

            CreateQuotation::dispatch($request, $quotation);

            // Refresh so status reflects its database default ('draft'), which the
            // model does not set on the instance, before it is serialised back.
            return $this->successResponse($this->present($this->loadFull($quotation->refresh())), __('Quotation created successfully'), 201);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function show($id)
    {
        try {
            if (!Auth::user()->can('view-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotation = SalesQuotation::where('id', $id)->visibleTo()->first();
            if (!$quotation) {
                return $this->errorResponse(__('Quotation not found'), null, 404);
            }

            return $this->successResponse($this->present($this->loadFull($quotation)), __('Quotation details retrieved successfully'));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function update(UpdateQuotationApiRequest $request, $id)
    {
        try {
            if (!Auth::user()->can('edit-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotation = $this->ownScoped($id);
            if (!$quotation) {
                return $this->errorResponse(__('Quotation not found'), null, 404);
            }

            if ($quotation->status !== 'draft') {
                return $this->errorResponse(__('Only draft quotations can be updated.'), null, 422);
            }

            DB::transaction(function () use ($request, $quotation) {
                $totals = $this->calculateTotals($request->items);

                $quotation->update([
                    'quotation_date'  => $request->invoice_date,
                    'due_date'        => $request->due_date,
                    'customer_id'     => $request->customer_id,
                    'warehouse_id'    => $request->warehouse_id,
                    'payment_terms'   => $request->payment_terms,
                    'notes'           => $request->notes,
                    'subtotal'        => $totals['subtotal'],
                    'tax_amount'      => $totals['tax_amount'],
                    'discount_amount' => $totals['discount_amount'],
                    'total_amount'    => $totals['total_amount'],
                ]);

                $quotation->items()->delete();
                $this->syncItems($quotation->id, $request->items);
            });

            UpdateQuotation::dispatch($request, $quotation);

            return $this->successResponse($this->present($this->loadFull($quotation)), __('Quotation updated successfully'));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function destroy($id)
    {
        try {
            if (!Auth::user()->can('delete-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotation = $this->ownScoped($id);
            if (!$quotation) {
                return $this->errorResponse(__('Quotation not found'), null, 404);
            }

            if ($quotation->status === 'sent') {
                return $this->errorResponse(__('Cannot delete sent quotation.'), null, 422);
            }

            DestroyQuotation::dispatch($quotation);
            $quotation->delete();

            return $this->successResponse(null, __('Quotation deleted successfully'));
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    public function sent($id)
    {
        return $this->transition($id, 'sent-quotations', 'draft', 'sent', SentSalesQuotation::class, __('Only draft quotations can be sent.'), __('Quotation sent successfully'));
    }

    public function approve($id)
    {
        return $this->transition($id, 'approve-quotations', 'sent', 'accepted', AcceptSalesQuotation::class, __('Only sent quotations can be approved.'), __('Quotation approved successfully'));
    }

    public function reject($id)
    {
        return $this->transition($id, 'reject-quotations', 'sent', 'rejected', RejectSalesQuotation::class, __('Only sent quotations can be rejected.'), __('Quotation rejected successfully'));
    }

    /**
     * The three status changes are the same shape: a permission, a required
     * current status, a new status, and an event. Kept in one place so the
     * guards cannot drift apart.
     */
    private function transition($id, string $permission, string $from, string $to, string $event, string $wrongStatus, string $success)
    {
        try {
            if (!Auth::user()->can($permission)) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $quotation = $this->ownScoped($id);
            if (!$quotation) {
                return $this->errorResponse(__('Quotation not found'), null, 404);
            }

            if ($quotation->status !== $from) {
                return $this->errorResponse($wrongStatus, null, 422);
            }

            $event::dispatch($quotation);
            $quotation->update(['status' => $to]);

            return $this->successResponse($this->present($this->loadFull($quotation)), $success);
        } catch (\Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Load a quotation the caller is allowed to change. Writes require the
     * company-owner relationship the web enforces (created_by === company),
     * which is stricter than the read scope on purpose.
     */
    private function ownScoped($id): ?SalesQuotation
    {
        $quotation = SalesQuotation::find($id);

        if (!$quotation || (int) $quotation->created_by !== creatorId()) {
            return null;
        }

        return $quotation;
    }

    private function loadFull(SalesQuotation $quotation): SalesQuotation
    {
        return $quotation->load(['customer:id,name,email', 'items.taxes', 'warehouse:id,name']);
    }

    /**
     * Recalculate header totals from the submitted line items. Kept identical to
     * QuotationController::calculateTotals.
     * ponytail: duplicated pure arithmetic, source of truth is the web
     * controller; centralise on the model if a third caller appears.
     */
    private function calculateTotals($items): array
    {
        $subtotal = 0;
        $totalTax = 0;
        $totalDiscount = 0;

        foreach ($items as $item) {
            $lineTotal      = $item['quantity'] * $item['unit_price'];
            $discountAmount = ($lineTotal * ($item['discount_percentage'] ?? 0)) / 100;
            $afterDiscount  = $lineTotal - $discountAmount;
            $taxAmount      = ($afterDiscount * ($item['tax_percentage'] ?? 0)) / 100;

            $subtotal      += $lineTotal;
            $totalDiscount += $discountAmount;
            $totalTax      += $taxAmount;
        }

        return [
            'subtotal'        => $subtotal,
            'tax_amount'      => $totalTax,
            'discount_amount' => $totalDiscount,
            'total_amount'    => $subtotal + $totalTax - $totalDiscount,
        ];
    }

    /** Create the line items and their per-item taxes. Mirrors QuotationController::createQuotationItems. */
    private function syncItems($quotationId, $items): void
    {
        foreach ($items as $itemData) {
            $item = new SalesQuotationItem();
            $item->quotation_id        = $quotationId;
            $item->product_id          = $itemData['product_id'];
            $item->quantity            = $itemData['quantity'];
            $item->unit_price          = $itemData['unit_price'];
            $item->discount_percentage = $itemData['discount_percentage'] ?? 0;
            $item->tax_percentage      = $itemData['tax_percentage'] ?? 0;
            // No creator_id/created_by: the sales_quotation_items table has neither
            // (the model's fillable lists them, but the columns do not exist), which
            // is why the web createQuotationItems never sets them either.
            $item->save();

            foreach ($itemData['taxes'] ?? [] as $tax) {
                $itemTax = new SalesQuotationItemTax();
                $itemTax->item_id  = $item->id;
                $itemTax->tax_name = $tax['tax_name'];
                $itemTax->tax_rate = $tax['tax_rate'] ?? $tax['rate'] ?? 0;
                $itemTax->save();
            }
        }
    }

    private function present(SalesQuotation $q): array
    {
        return [
            'id'               => $q->id,
            'quotation_number' => $q->quotation_number,
            'quotation_date'   => $q->quotation_date?->format('Y-m-d'),
            'due_date'         => $q->due_date?->format('Y-m-d'),
            'customer_id'      => $q->customer_id,
            'customer_name'    => $q->customer->name ?? null,
            'warehouse_id'     => $q->warehouse_id,
            'status'           => $q->status,
            'subtotal'         => $q->subtotal,
            'tax_amount'       => $q->tax_amount,
            'discount_amount'  => $q->discount_amount,
            'total_amount'     => $q->total_amount,
            'payment_terms'    => $q->payment_terms,
            'notes'            => $q->notes,
            'converted_to_invoice' => $q->converted_to_invoice,
            'created_by'       => $q->created_by,
            'items'            => $q->relationLoaded('items') ? $q->items->map(fn ($item) => [
                'id'                  => $item->id,
                'product_id'          => $item->product_id,
                'quantity'            => $item->quantity,
                'unit_price'          => $item->unit_price,
                'discount_percentage' => $item->discount_percentage,
                'tax_percentage'      => $item->tax_percentage,
                'total_amount'        => $item->total_amount,
                'taxes'               => $item->relationLoaded('taxes') ? $item->taxes->map(fn ($t) => [
                    'tax_name' => $t->tax_name,
                    'tax_rate' => $t->tax_rate,
                ]) : [],
            ]) : [],
        ];
    }

    private function sortField(Request $request): string
    {
        $sort = $request->get('sort', 'created_at');
        return in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
    }

    private function sortDirection(Request $request): string
    {
        return in_array($request->get('direction'), ['asc', 'desc'], true) ? $request->get('direction') : 'desc';
    }

    private function fail(\Throwable $e)
    {
        Log::error('Quotation API error', ['message' => $e->getMessage()]);
        return $this->errorResponse(__('Something went wrong'), null, 500);
    }
}
