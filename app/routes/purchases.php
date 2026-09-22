<?php

declare(strict_types=1);

use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
Route::middleware('project.owner')->group(function (): void {
    Route::post('purchases/sources', [PurchaseController::class, 'source'])->name('purchases.sources.store');
    Route::patch('purchases/sources/{source}', [PurchaseController::class, 'updateSource'])->name('purchases.sources.update');
    Route::post('purchases/records', [PurchaseController::class, 'record'])->middleware('throttle:30,1')->name('purchases.records.store');
});
