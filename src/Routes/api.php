<?php

use Illuminate\Support\Facades\Route;
use Zerp\Quotation\Http\Controllers\Api\DashboardApiController;
use Zerp\Quotation\Http\Controllers\Api\QuotationApiController;

Route::prefix('api')->middleware(['api.json'])->group(function () {
    Route::group(['middleware' => ['auth:sanctum'], 'prefix' => 'quotation'], function () {
        Route::get('dashboard', [DashboardApiController::class, 'index']);

        Route::post('quotations/{id}/sent', [QuotationApiController::class, 'sent']);
        Route::post('quotations/{id}/approve', [QuotationApiController::class, 'approve']);
        Route::post('quotations/{id}/reject', [QuotationApiController::class, 'reject']);

        Route::apiResource('quotations', QuotationApiController::class);
    });
});
