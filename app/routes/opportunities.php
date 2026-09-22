<?php

declare(strict_types=1);

use App\Http\Controllers\PageOpportunityController;
use Illuminate\Support\Facades\Route;

Route::get('plan', [PageOpportunityController::class, 'index'])->name('opportunities.index');
Route::post('plan/refresh', [PageOpportunityController::class, 'refresh'])->middleware(['project.owner', 'throttle:5,1'])->name('opportunities.refresh');
Route::post('opportunities', [PageOpportunityController::class, 'store'])->middleware(['project.owner', 'throttle:10,1'])->name('opportunities.store');
Route::post('opportunities/{opportunity}/dismiss', [PageOpportunityController::class, 'dismiss'])->middleware('project.owner')->name('opportunities.dismiss');
Route::post('opportunities/{opportunity}/reconsider', [PageOpportunityController::class, 'reconsider'])->middleware('project.owner')->name('opportunities.reconsider');
