<?php

use App\Modules\Orders\Http\Controllers\Web\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view orders')->group(function () {
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});
Route::post('/orders/{order}/void', [OrderController::class, 'void'])
    ->middleware('can:void orders')
    ->name('orders.void');
Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markAsPaid'])
    ->middleware('can:void orders')
    ->name('orders.mark-paid');
