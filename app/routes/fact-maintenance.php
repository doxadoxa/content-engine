<?php

declare(strict_types=1);

use App\Http\Controllers\FactMaintenanceController;
use Illuminate\Support\Facades\Route;

Route::get('fact-maintenance', [FactMaintenanceController::class, 'index'])->name('fact-maintenance.index');
Route::get('fact-maintenance/{check}', [FactMaintenanceController::class, 'show'])->name('fact-maintenance.show');
Route::middleware(['project.owner', 'throttle:5,1'])->group(function (): void {
    Route::post('fact-maintenance', [FactMaintenanceController::class, 'start'])->name('fact-maintenance.start');
    Route::post('fact-maintenance/claims/{claim}/review', [FactMaintenanceController::class, 'review'])->name('fact-maintenance.review');
});
