<?php

use Illuminate\Support\Facades\Route;
use Zerp\Quotation\Http\Controllers\Api\DashboardApiController;
use Zerp\Quotation\Http\Controllers\Api\QuotationApiController;

// The 'as' prefix keeps apiResource's auto-generated names (quotations.index,
// quotations.store, ...) from colliding with the web resource's identical names,
// which breaks route:cache in production. Namespacing them under api.quotation.
// keeps them distinct without touching the web routes.
Route::prefix('api')->middleware(['api.json'])->group(function () {
    Route::group(['middleware' => ['auth:sanctum'], 'prefix' => 'quotation', 'as' => 'api.quotation.'], function () {
        Route::get('dashboard', [DashboardApiController::class, 'index']);

        Route::post('quotations/{id}/sent', [QuotationApiController::class, 'sent']);
        Route::post('quotations/{id}/approve', [QuotationApiController::class, 'approve']);
        Route::post('quotations/{id}/reject', [QuotationApiController::class, 'reject']);

        Route::apiResource('quotations', QuotationApiController::class);
    });
});
