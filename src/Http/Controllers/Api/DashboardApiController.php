<?php

namespace Zerp\Quotation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Zerp\Quotation\Models\SalesQuotation;

/**
 * Summary numbers for the Quotation home screen, scoped the same way the list
 * is. See zerp-pk/zerp#25.
 */
class DashboardApiController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request)
    {
        try {
            if (!Auth::user()->can('manage-quotations')) {
                return $this->errorResponse(__('Permission denied'), null, 403);
            }

            $base = fn () => SalesQuotation::query()->where(function ($q) {
                if (Auth::user()->can('manage-any-quotations')) {
                    $q->where('created_by', creatorId());
                } elseif (Auth::user()->can('manage-own-quotations')) {
                    $q->where(function ($inner) {
                        $inner->where('creator_id', Auth::id())->orWhere('customer_id', Auth::id());
                    });
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            $byStatus = (clone $base())
                ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as value')
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => [
                    'status' => $row->status,
                    'count'  => (int) $row->count,
                    'value'  => round((float) $row->value, 2),
                ]);

            $recent = (clone $base())->with('customer:id,name')->latest()->limit(5)->get()
                ->map(fn ($q) => [
                    'id'               => $q->id,
                    'quotation_number' => $q->quotation_number,
                    'customer_name'    => $q->customer->name ?? null,
                    'status'           => $q->status,
                    'total_amount'     => $q->total_amount,
                    'quotation_date'   => $q->quotation_date?->format('Y-m-d'),
                ]);

            return $this->successResponse([
                'stats' => [
                    'total_quotations' => (clone $base())->count(),
                    'total_value'      => round((float) (clone $base())->sum('total_amount'), 2),
                    'accepted_value'   => round((float) (clone $base())->where('status', 'accepted')->sum('total_amount'), 2),
                ],
                'by_status'      => $byStatus,
                'recent'         => $recent,
            ], __('Dashboard retrieved successfully'));
        } catch (\Throwable $e) {
            Log::error('Quotation dashboard API error', ['message' => $e->getMessage()]);
            return $this->errorResponse(__('Something went wrong'), null, 500);
        }
    }
}
