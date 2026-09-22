<?php

declare(strict_types=1);

use App\Http\Controllers\BusinessFactController;
use App\Http\Controllers\SitePageController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated current-project group in web.php.
Route::get('pages', [SitePageController::class, 'index'])->name('pages.index');
Route::post('pages', [SitePageController::class, 'store'])->middleware(['project.owner', 'throttle:20,1'])->name('pages.store');
Route::post('pages/discover', [SitePageController::class, 'discover'])->middleware(['project.owner', 'throttle:5,1'])->name('pages.discover');
Route::get('pages/{page}', [SitePageController::class, 'show'])->name('pages.show');
Route::post('pages/{page}/snapshots', [SitePageController::class, 'capture'])->middleware(['project.owner', 'throttle:20,1'])->name('pages.capture');
Route::post('pages/{page}/untrack', [SitePageController::class, 'untrack'])->middleware('project.owner')->name('pages.untrack');
Route::get('business-facts', [BusinessFactController::class, 'index'])->name('business-facts.index');
Route::post('business-facts', [BusinessFactController::class, 'store'])->middleware('project.owner')->name('business-facts.store');
Route::patch('business-facts/{fact}', [BusinessFactController::class, 'update'])->middleware('project.owner')->name('business-facts.update');
