<?php

declare(strict_types=1);

use App\Http\Controllers\PagePerformanceController;
use Illuminate\Support\Facades\Route;

Route::get('performance', [PagePerformanceController::class, 'index'])->name('performance.index');
Route::post('performance/read', [PagePerformanceController::class, 'read'])->middleware(['project.owner', 'throttle:2,1'])->name('performance.read');
